"""Organization v0 应用层投影存储（SQLite）。

权威关系（本库不是真相源）：
- raw occurrence 的真相源 = Obsidian Vault 第一发生场.jsonl（append-only）
- runtime 连续性 = web/.state/*_thread.json
- 本库只保存 组织 / 项目 / 成员 的 application projection / state。

Project 在这里只是普通软件对象，不是 Cognos 的 Space；
Task/Member/Assignment 等概念也不得映射成 Attempt/Subject/Relation。
"""

from __future__ import annotations

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
CREATE TABLE IF NOT EXISTS organizations (
    id TEXT PRIMARY KEY,
    name TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS projects (
    id TEXT PRIMARY KEY,
    org_id TEXT NOT NULL REFERENCES organizations(id),
    title TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'active',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS org_members (
    id TEXT PRIMARY KEY,
    org_id TEXT NOT NULL REFERENCES organizations(id),
    kind TEXT NOT NULL CHECK (kind IN ('human', 'ai')),
    name TEXT NOT NULL,
    participant_id TEXT,
    status TEXT NOT NULL DEFAULT 'active',
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS project_members (
    project_id TEXT NOT NULL REFERENCES projects(id),
    org_member_id TEXT NOT NULL REFERENCES org_members(id),
    PRIMARY KEY (project_id, org_member_id)
);
"""


class OrgStore:
    """SQLite 投影库；每次操作短连接，事务内完成。"""

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
        """事务上下文：提交/回滚后一定关闭连接（Windows 文件锁）。"""
        conn = self._connect()
        try:
            with conn:
                yield conn
        finally:
            conn.close()

    # ---------- 组织 ----------

    def list_organizations(self) -> list[dict[str, Any]]:
        with self._conn() as conn:
            rows = conn.execute(
                """
                SELECT o.*, (SELECT COUNT(*) FROM projects p WHERE p.org_id = o.id) AS project_count
                FROM organizations o ORDER BY o.created_at
                """
            ).fetchall()
        return [dict(r) for r in rows]

    def get_organization(self, org_id: str) -> dict[str, Any] | None:
        with self._conn() as conn:
            row = conn.execute(
                "SELECT * FROM organizations WHERE id = ?", (org_id,)
            ).fetchone()
        return dict(row) if row is not None else None

    def create_organization(self, name: str, description: str = "") -> dict[str, Any]:
        org = {
            "id": _new_id(),
            "name": name,
            "description": description,
            "created_at": _now(),
        }
        with self._conn() as conn:
            conn.execute(
                "INSERT INTO organizations (id, name, description, created_at) VALUES (?, ?, ?, ?)",
                (org["id"], org["name"], org["description"], org["created_at"]),
            )
        return org

    # ---------- 项目 ----------

    def list_projects(self, org_id: str) -> list[dict[str, Any]]:
        with self._conn() as conn:
            rows = conn.execute(
                "SELECT * FROM projects WHERE org_id = ? ORDER BY created_at", (org_id,)
            ).fetchall()
        return [dict(r) for r in rows]

    def get_project(self, project_id: str) -> dict[str, Any] | None:
        with self._conn() as conn:
            row = conn.execute(
                "SELECT * FROM projects WHERE id = ?", (project_id,)
            ).fetchone()
        return dict(row) if row is not None else None

    def create_project(self, org_id: str, title: str, description: str = "") -> dict[str, Any]:
        now = _now()
        project = {
            "id": _new_id(),
            "org_id": org_id,
            "title": title,
            "description": description,
            "status": "active",
            "created_at": now,
            "updated_at": now,
        }
        with self._conn() as conn:
            conn.execute(
                """
                INSERT INTO projects (id, org_id, title, description, status, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                """,
                (project["id"], org_id, title, description, project["status"], now, now),
            )
        return project

    # ---------- 组织成员 ----------

    def list_org_members(self, org_id: str) -> list[dict[str, Any]]:
        with self._conn() as conn:
            rows = conn.execute(
                "SELECT * FROM org_members WHERE org_id = ? ORDER BY created_at", (org_id,)
            ).fetchall()
        return [dict(r) for r in rows]

    def add_org_member(
        self,
        org_id: str,
        kind: str,
        name: str,
        participant_id: str | None = None,
    ) -> dict[str, Any]:
        member = {
            "id": _new_id(),
            "org_id": org_id,
            "kind": kind,
            "name": name,
            "participant_id": participant_id,
            "status": "active",
            "created_at": _now(),
        }
        with self._conn() as conn:
            conn.execute(
                """
                INSERT INTO org_members (id, org_id, kind, name, participant_id, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                """,
                (member["id"], org_id, kind, name, participant_id, member["status"], member["created_at"]),
            )
        return member

    # ---------- 项目成员 ----------

    def add_project_member(self, project_id: str, org_member_id: str) -> None:
        with self._conn() as conn:
            project = conn.execute(
                "SELECT org_id FROM projects WHERE id = ?", (project_id,)
            ).fetchone()
            member = conn.execute(
                "SELECT org_id FROM org_members WHERE id = ?", (org_member_id,)
            ).fetchone()
            if member is None:
                raise ValueError("成员不存在")
            if project is None or project["org_id"] != member["org_id"]:
                raise ValueError("成员不属于该项目所在组织")
            conn.execute(
                "INSERT OR IGNORE INTO project_members (project_id, org_member_id) VALUES (?, ?)",
                (project_id, org_member_id),
            )

    def list_project_members(self, project_id: str) -> list[dict[str, Any]]:
        with self._conn() as conn:
            rows = conn.execute(
                """
                SELECT m.* FROM org_members m
                JOIN project_members pm ON pm.org_member_id = m.id
                WHERE pm.project_id = ? ORDER BY m.created_at
                """,
                (project_id,),
            ).fetchall()
        return [dict(r) for r in rows]
