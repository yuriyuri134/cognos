"""SQLite projection for the first personal note-space slice."""
from __future__ import annotations

import hashlib
import json
import sqlite3
import uuid
from contextlib import contextmanager
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Iterator


def _now() -> str:
    return datetime.now(timezone.utc).isoformat()


def _id() -> str:
    return str(uuid.uuid4())


_SCHEMA = """
CREATE TABLE IF NOT EXISTS note_spaces (
    id TEXT PRIMARY KEY,
    title TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    created_by TEXT NOT NULL,
    owner_account_id TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS notes (
    id TEXT PRIMARY KEY,
    space_id TEXT NOT NULL REFERENCES note_spaces(id) ON DELETE CASCADE,
    title TEXT NOT NULL,
    content TEXT NOT NULL,
    source_json TEXT NOT NULL DEFAULT '{}',
    created_by TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    revision INTEGER NOT NULL DEFAULT 1,
    content_hash TEXT NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS notes_space ON notes(space_id, updated_at);
CREATE TABLE IF NOT EXISTS note_links (
    id TEXT PRIMARY KEY,
    space_id TEXT NOT NULL REFERENCES note_spaces(id) ON DELETE CASCADE,
    source_note_id TEXT NOT NULL REFERENCES notes(id) ON DELETE CASCADE,
    target_note_id TEXT NOT NULL REFERENCES notes(id) ON DELETE CASCADE,
    label TEXT NOT NULL DEFAULT '',
    created_by TEXT NOT NULL,
    created_at TEXT NOT NULL,
    UNIQUE(space_id, source_note_id, target_note_id)
);
CREATE INDEX IF NOT EXISTS note_links_space ON note_links(space_id, created_at);
CREATE TABLE IF NOT EXISTS note_messages (
    id TEXT PRIMARY KEY,
    space_id TEXT NOT NULL REFERENCES note_spaces(id) ON DELETE CASCADE,
    note_id TEXT REFERENCES notes(id) ON DELETE SET NULL,
    author TEXT NOT NULL,
    participant_id TEXT,
    content TEXT NOT NULL,
    reply_to TEXT,
    runtime_ref_json TEXT NOT NULL DEFAULT '{}',
    created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS note_messages_space ON note_messages(space_id, created_at);
"""


class NotesNotFound(ValueError):
    pass


class NotesStore:
    def __init__(self, path: Path) -> None:
        self.path = Path(path)
        self.path.parent.mkdir(parents=True, exist_ok=True)
        with self._conn() as conn:
            conn.executescript(_SCHEMA)
            columns = {row[1] for row in conn.execute("PRAGMA table_info(note_spaces)")}
            if "owner_account_id" not in columns:
                conn.execute("ALTER TABLE note_spaces ADD COLUMN owner_account_id TEXT NOT NULL DEFAULT ''")
            note_columns = {row[1] for row in conn.execute("PRAGMA table_info(notes)")}
            if "revision" not in note_columns:
                conn.execute("ALTER TABLE notes ADD COLUMN revision INTEGER NOT NULL DEFAULT 1")
            if "content_hash" not in note_columns:
                conn.execute("ALTER TABLE notes ADD COLUMN content_hash TEXT NOT NULL DEFAULT ''")

    def _connect(self) -> sqlite3.Connection:
        conn = sqlite3.connect(self.path, timeout=10)
        conn.row_factory = sqlite3.Row
        conn.execute("PRAGMA foreign_keys = ON")
        return conn

    @contextmanager
    def _conn(self) -> Iterator[sqlite3.Connection]:
        conn = self._connect()
        try:
            with conn:
                yield conn
        finally:
            conn.close()

    @staticmethod
    def _space(row: sqlite3.Row) -> dict[str, Any]:
        return dict(row)

    @staticmethod
    def _note(row: sqlite3.Row) -> dict[str, Any]:
        value = dict(row)
        value["source"] = json.loads(value.pop("source_json") or "{}")
        return value

    @staticmethod
    def _link(row: sqlite3.Row) -> dict[str, Any]:
        return dict(row)

    def list_spaces(self, owner_account_id: str) -> list[dict[str, Any]]:
        with self._conn() as conn:
            rows = conn.execute(
                "SELECT s.*, COUNT(n.id) AS note_count FROM note_spaces s "
                "LEFT JOIN notes n ON n.space_id=s.id WHERE s.owner_account_id=? "
                "GROUP BY s.id ORDER BY s.updated_at DESC", (owner_account_id,)
            ).fetchall()
        return [dict(row) for row in rows]

    def create_space(self, title: str, description: str, actor: str, owner_account_id: str) -> dict[str, Any]:
        now = _now()
        space = {"id": _id(), "title": title, "description": description,
                 "created_by": actor, "owner_account_id": owner_account_id,
                 "created_at": now, "updated_at": now}
        with self._conn() as conn:
            conn.execute(
                "INSERT INTO note_spaces "
                "(id, title, description, created_by, owner_account_id, created_at, updated_at) "
                "VALUES (?, ?, ?, ?, ?, ?, ?)",
                (space["id"], space["title"], space["description"], space["created_by"],
                 space["owner_account_id"], space["created_at"], space["updated_at"]),
            )
        return {**space, "note_count": 0}

    def get_space(self, space_id: str, owner_account_id: str) -> dict[str, Any]:
        with self._conn() as conn:
            space = conn.execute(
                "SELECT * FROM note_spaces WHERE id=? AND owner_account_id=?",
                (space_id, owner_account_id),
            ).fetchone()
            if space is None:
                raise NotesNotFound("笔记空间不存在或无权访问")
            notes = conn.execute("SELECT * FROM notes WHERE space_id=? ORDER BY updated_at", (space_id,)).fetchall()
            links = conn.execute("SELECT * FROM note_links WHERE space_id=? ORDER BY created_at", (space_id,)).fetchall()
        return {**self._space(space), "notes": [self._note(row) for row in notes],
                "links": [self._link(row) for row in links]}

    def create_note(self, space_id: str, title: str, content: str,
                    source: dict[str, Any], actor: str, owner_account_id: str) -> dict[str, Any]:
        now = _now()
        content_hash = hashlib.sha256(content.encode("utf-8")).hexdigest()
        note = {"id": _id(), "space_id": space_id, "title": title,
                "content": content, "source_json": json.dumps(source, ensure_ascii=False),
                "created_by": actor, "created_at": now, "updated_at": now,
                "revision": 1, "content_hash": content_hash}
        with self._conn() as conn:
            if conn.execute("SELECT 1 FROM note_spaces WHERE id=? AND owner_account_id=?",
                            (space_id, owner_account_id)).fetchone() is None:
                raise NotesNotFound("笔记空间不存在或无权访问")
            conn.execute("""INSERT INTO notes
                (id, space_id, title, content, source_json, created_by, created_at, updated_at, revision, content_hash)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)""", tuple(note.values()))
            conn.execute("UPDATE note_spaces SET updated_at=? WHERE id=?", (now, space_id))
            row = conn.execute("SELECT * FROM notes WHERE id=?", (note["id"],)).fetchone()
        return self._note(row)

    def update_note(self, note_id: str, title: str, content: str, actor: str,
                    owner_account_id: str) -> dict[str, Any]:
        now = _now()
        content_hash = hashlib.sha256(content.encode("utf-8")).hexdigest()
        with self._conn() as conn:
            row = conn.execute("""SELECT n.* FROM notes n JOIN note_spaces s ON s.id=n.space_id
                                 WHERE n.id=? AND s.owner_account_id=?""",
                               (note_id, owner_account_id)).fetchone()
            if row is None:
                raise NotesNotFound("笔记不存在或无权访问")
            conn.execute("""UPDATE notes SET title=?, content=?, updated_at=?, revision=?, content_hash=?
                           WHERE id=?""",
                         (title, content, now, row["revision"] + 1, content_hash, note_id))
            conn.execute("UPDATE note_spaces SET updated_at=? WHERE id=?", (now, row["space_id"]))
            fresh = conn.execute("SELECT * FROM notes WHERE id=?", (note_id,)).fetchone()
        return self._note(fresh)

    def create_link(self, space_id: str, source_note_id: str, target_note_id: str,
                    label: str, actor: str) -> dict[str, Any]:
        if source_note_id == target_note_id:
            raise ValueError("不能把笔记连接到自身")
        link = {"id": _id(), "space_id": space_id, "source_note_id": source_note_id,
                "target_note_id": target_note_id, "label": label,
                "created_by": actor, "created_at": _now()}
        with self._conn() as conn:
            notes = conn.execute("SELECT id, space_id FROM notes WHERE id IN (?, ?)",
                                 (source_note_id, target_note_id)).fetchall()
            if len(notes) != 2 or any(row["space_id"] != space_id for row in notes):
                raise ValueError("连接的两份笔记必须属于当前空间")
            existing = conn.execute(
                "SELECT * FROM note_links WHERE space_id=? AND source_note_id=? AND target_note_id=?",
                (space_id, source_note_id, target_note_id),
            ).fetchone()
            if existing is not None:
                return self._link(existing)
            conn.execute("INSERT INTO note_links VALUES (?, ?, ?, ?, ?, ?, ?)", tuple(link.values()))
            conn.execute("UPDATE note_spaces SET updated_at=? WHERE id=?", (link["created_at"], space_id))
        return link

    def list_messages(self, space_id: str, owner_account_id: str) -> list[dict[str, Any]]:
        with self._conn() as conn:
            allowed = conn.execute("SELECT 1 FROM note_spaces WHERE id=? AND owner_account_id=?",
                                   (space_id, owner_account_id)).fetchone()
            if allowed is None:
                raise NotesNotFound("笔记空间不存在或无权访问")
            rows = conn.execute("SELECT * FROM note_messages WHERE space_id=? ORDER BY created_at",
                                (space_id,)).fetchall()
        values = []
        for row in rows:
            item = dict(row)
            item["runtime_ref"] = json.loads(item.pop("runtime_ref_json") or "{}")
            values.append(item)
        return values

    def get_message(self, message_id: str, owner_account_id: str) -> dict[str, Any]:
        with self._conn() as conn:
            row = conn.execute("""SELECT m.* FROM note_messages m
                JOIN note_spaces s ON s.id=m.space_id
                WHERE m.id=? AND s.owner_account_id=?""", (message_id, owner_account_id)).fetchone()
        if row is None:
            raise NotesNotFound("消息不存在或无权访问")
        item = dict(row)
        item["runtime_ref"] = json.loads(item.pop("runtime_ref_json") or "{}")
        return item

    def get_note(self, space_id: str, note_id: str, owner_account_id: str) -> dict[str, Any]:
        with self._conn() as conn:
            allowed = conn.execute("SELECT 1 FROM note_spaces WHERE id=? AND owner_account_id=?",
                                   (space_id, owner_account_id)).fetchone()
            if allowed is None:
                raise NotesNotFound("笔记空间不存在或无权访问")
            row = conn.execute("SELECT * FROM notes WHERE id=? AND space_id=?",
                               (note_id, space_id)).fetchone()
        if row is None:
            raise NotesNotFound("笔记不属于当前空间")
        return self._note(row)

    def add_message(self, space_id: str, owner_account_id: str, *, note_id: str | None,
                    author: str, participant_id: str | None, content: str,
                    reply_to: str | None = None, runtime_ref: dict[str, Any] | None = None) -> dict[str, Any]:
        now = _now()
        message = {"id": _id(), "space_id": space_id, "note_id": note_id,
                   "author": author, "participant_id": participant_id, "content": content,
                   "reply_to": reply_to, "runtime_ref_json": json.dumps(runtime_ref or {}, ensure_ascii=False),
                   "created_at": now}
        with self._conn() as conn:
            allowed = conn.execute("SELECT 1 FROM note_spaces WHERE id=? AND owner_account_id=?",
                                   (space_id, owner_account_id)).fetchone()
            if allowed is None:
                raise NotesNotFound("笔记空间不存在或无权访问")
            if note_id and conn.execute("SELECT 1 FROM notes WHERE id=? AND space_id=?",
                                       (note_id, space_id)).fetchone() is None:
                raise ValueError("笔记不属于当前空间")
            conn.execute("INSERT INTO note_messages VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)", tuple(message.values()))
            conn.execute("UPDATE note_spaces SET updated_at=? WHERE id=?", (now, space_id))
        result = dict(message)
        result["runtime_ref"] = json.loads(result.pop("runtime_ref_json"))
        return result
