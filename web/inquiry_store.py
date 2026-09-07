"""探究（question + 多次可再进入的尝试）应用层投影存储（SQLite）。

Situated Cognition 的下一步小 delta：让同一问题上的多次展开尝试接成一个
可再进入的过程。question / attempt 是普通应用层对象，不是新本体。

权威关系不变：raw occurrence（第一发生场.jsonl）是真相源；
本库只存探究与尝试的应用投影，attempt 引用 occurrence_id，不复制全文。
"""

from __future__ import annotations

import json
import sqlite3
import uuid
from contextlib import contextmanager
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Iterator


def _now() -> str:
    return datetime.now(timezone.utc).isoformat()


def _new_id() -> str:
    return str(uuid.uuid4())


_SCHEMA = """
CREATE TABLE IF NOT EXISTS inquiries (
    id TEXT PRIMARY KEY,
    project_id TEXT NOT NULL,
    question TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'open',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS attempts (
    id TEXT PRIMARY KEY,
    inquiry_id TEXT NOT NULL REFERENCES inquiries(id),
    attempt_no INTEGER NOT NULL,
    participant_id TEXT NOT NULL,
    occurrence_id TEXT NOT NULL,
    working_report TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL
);
"""


class InquiryStore:
    """探究投影库；与 OrgStore 共享同一 SQLite 文件也可以，这里独立成类。"""

    def __init__(self, path: Path) -> None:
        self.path = Path(path)
        self.path.parent.mkdir(parents=True, exist_ok=True)
        with self._conn() as conn:
            conn.executescript(_SCHEMA)

    def _connect(self) -> sqlite3.Connection:
        conn = sqlite3.connect(self.path)
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

    # ---------- 探究 ----------

    def create_inquiry(self, project_id: str, question: str) -> dict[str, Any]:
        now = _now()
        inquiry = {
            "id": _new_id(),
            "project_id": project_id,
            "question": question,
            "status": "open",
            "created_at": now,
            "updated_at": now,
        }
        with self._conn() as conn:
            conn.execute(
                "INSERT INTO inquiries (id, project_id, question, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)",
                (inquiry["id"], project_id, question, "open", now, now),
            )
        return inquiry

    def get_inquiry(self, inquiry_id: str) -> dict[str, Any] | None:
        with self._conn() as conn:
            row = conn.execute(
                "SELECT * FROM inquiries WHERE id = ?", (inquiry_id,)
            ).fetchone()
        return dict(row) if row is not None else None

    def list_inquiries(self, project_id: str) -> list[dict[str, Any]]:
        with self._conn() as conn:
            rows = conn.execute(
                """
                SELECT i.*, (SELECT COUNT(*) FROM attempts a WHERE a.inquiry_id = i.id) AS attempt_count
                FROM inquiries i WHERE i.project_id = ? ORDER BY i.created_at
                """,
                (project_id,),
            ).fetchall()
        return [dict(r) for r in rows]

    def update_inquiry_status(self, inquiry_id: str, status: str) -> None:
        with self._conn() as conn:
            conn.execute(
                "UPDATE inquiries SET status = ?, updated_at = ? WHERE id = ?",
                (status, _now(), inquiry_id),
            )

    # ---------- 尝试 ----------

    def next_attempt_no(self, inquiry_id: str) -> int:
        with self._conn() as conn:
            row = conn.execute(
                "SELECT COALESCE(MAX(attempt_no), 0) + 1 AS n FROM attempts WHERE inquiry_id = ?",
                (inquiry_id,),
            ).fetchone()
        return int(row["n"])

    def add_attempt(
        self,
        inquiry_id: str,
        attempt_no: int,
        participant_id: str,
        occurrence_id: str,
        working_report: str,
    ) -> dict[str, Any]:
        attempt = {
            "id": _new_id(),
            "inquiry_id": inquiry_id,
            "attempt_no": attempt_no,
            "participant_id": participant_id,
            "occurrence_id": occurrence_id,
            "working_report": working_report,
            "created_at": _now(),
        }
        with self._conn() as conn:
            conn.execute(
                """
                INSERT INTO attempts (id, inquiry_id, attempt_no, participant_id, occurrence_id, working_report, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                """,
                (
                    attempt["id"],
                    inquiry_id,
                    attempt_no,
                    participant_id,
                    occurrence_id,
                    working_report,
                    attempt["created_at"],
                ),
            )
            conn.execute(
                "UPDATE inquiries SET updated_at = ? WHERE id = ?",
                (attempt["created_at"], inquiry_id),
            )
        return attempt

    def list_attempts(self, inquiry_id: str) -> list[dict[str, Any]]:
        with self._conn() as conn:
            rows = conn.execute(
                "SELECT * FROM attempts WHERE inquiry_id = ? ORDER BY attempt_no",
                (inquiry_id,),
            ).fetchall()
        attempts = []
        for row in rows:
            item = dict(row)
            try:
                item["working_report"] = json.loads(item.get("working_report") or "{}")
            except Exception:
                item["working_report"] = {}
            attempts.append(item)
        return attempts
