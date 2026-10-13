"""Organization v0 应用层投影存储（SQLite）。

权威关系（本库不是真相源）：
- raw occurrence 的真相源 = Obsidian Vault 第一发生场.jsonl（append-only）
- runtime 连续性 = web/.state/*_thread.json
- 本库只保存 组织 / 项目 / 成员 的 application projection / state。

Project 在这里只是普通软件对象，不是 Cognos 的 Space；
Task/Member/Assignment 等概念也不得映射成 Attempt/Subject/Relation。
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

-- 会话：普通软件对象（不是 Space）。Slice 1 只启用项目默认频道。
CREATE TABLE IF NOT EXISTS conversations (
    id TEXT PRIMARY KEY,
    org_id TEXT NOT NULL REFERENCES organizations(id),
    project_id TEXT REFERENCES projects(id),
    kind TEXT NOT NULL DEFAULT 'channel',
    title TEXT NOT NULL,
    is_default INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'active',
    created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS conversations_project ON conversations(project_id, created_at);

CREATE TABLE IF NOT EXISTS conversation_members (
    conversation_id TEXT NOT NULL REFERENCES conversations(id),
    org_member_id TEXT NOT NULL REFERENCES org_members(id),
    PRIMARY KEY (conversation_id, org_member_id)
);

CREATE TABLE IF NOT EXISTS organization_invitations (
    id TEXT PRIMARY KEY,
    org_id TEXT NOT NULL REFERENCES organizations(id),
    inviter_account_id TEXT NOT NULL,
    invitee_account_id TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending',
    created_at TEXT NOT NULL,
    responded_at TEXT
);
CREATE INDEX IF NOT EXISTS organization_invitations_invitee
    ON organization_invitations(invitee_account_id, status, created_at);
CREATE UNIQUE INDEX IF NOT EXISTS organization_invitations_pending_pair
    ON organization_invitations(org_id, invitee_account_id)
    WHERE status = 'pending';

CREATE TABLE IF NOT EXISTS direct_conversations (
    id TEXT PRIMARY KEY,
    account_a_id TEXT NOT NULL,
    account_b_id TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'active',
    created_at TEXT NOT NULL,
    UNIQUE(account_a_id, account_b_id)
);
CREATE INDEX IF NOT EXISTS direct_conversations_account_a
    ON direct_conversations(account_a_id, created_at);
CREATE INDEX IF NOT EXISTS direct_conversations_account_b
    ON direct_conversations(account_b_id, created_at);

-- 决定：对原发生/讨论的项目级投影，不替换原消息。
CREATE TABLE IF NOT EXISTS decisions (
    id TEXT PRIMARY KEY,
    project_id TEXT NOT NULL REFERENCES projects(id),
    content TEXT NOT NULL,
    source_refs TEXT NOT NULL DEFAULT '[]',
    decided_by TEXT NOT NULL DEFAULT '',
    decided_at TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'accepted',
    reopen_condition TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS decisions_project ON decisions(project_id, decided_at);

-- 工作项：普通软件对象（不是 Attempt）。
CREATE TABLE IF NOT EXISTS work_items (
    id TEXT PRIMARY KEY,
    project_id TEXT NOT NULL REFERENCES projects(id),
    title TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'open',
    assignee_member_id TEXT,
    source_refs TEXT NOT NULL DEFAULT '[]',
    decision_id TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS work_items_project ON work_items(project_id, created_at);

CREATE TABLE IF NOT EXISTS work_item_events (
    id TEXT PRIMARY KEY,
    work_item_id TEXT NOT NULL REFERENCES work_items(id),
    kind TEXT NOT NULL,
    detail TEXT NOT NULL DEFAULT '',
    actor TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS work_item_events_item ON work_item_events(work_item_id, created_at);
"""

WORK_ITEM_STATUSES = ("open", "in_progress", "blocked", "done")
DECISION_STATUSES = ("accepted", "proposed", "rejected", "superseded")

_UNSET: Any = object()


_ORG_MEMBER_EXTRA_COLUMNS = (
    ("org_role", "TEXT NOT NULL DEFAULT ''"),
    ("materials", "TEXT NOT NULL DEFAULT ''"),
    ("account_id", "TEXT"),
)
_PROJECT_MEMBER_EXTRA_COLUMNS = (
    ("position", "TEXT NOT NULL DEFAULT ''"),
)


def _column_names(conn: sqlite3.Connection, table: str) -> set[str]:
    return {row[1] for row in conn.execute(f"PRAGMA table_info({table})")}


class OrgStore:
    """SQLite 投影库；每次操作短连接，事务内完成。"""

    def __init__(self, path: Path) -> None:
        self.path = Path(path)
        self.path.parent.mkdir(parents=True, exist_ok=True)
        with self._conn() as conn:
            conn.executescript(_SCHEMA)
            # 幂等迁移：旧库补新增列（普通软件字段，不宣称本体）
            for table, extra in (
                ("org_members", _ORG_MEMBER_EXTRA_COLUMNS),
                ("project_members", _PROJECT_MEMBER_EXTRA_COLUMNS),
            ):
                existing = _column_names(conn, table)
                for name, ddl in extra:
                    if name not in existing:
                        conn.execute(f"ALTER TABLE {table} ADD COLUMN {name} {ddl}")
            conn.execute("CREATE UNIQUE INDEX IF NOT EXISTS org_member_account "
                         "ON org_members(org_id, account_id) WHERE account_id IS NOT NULL")

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

    def account_member(self, org_id: str, account_id: str) -> dict[str, Any] | None:
        with self._conn() as conn:
            row = conn.execute(
                "SELECT * FROM org_members WHERE org_id = ? AND account_id = ?",
                (org_id, account_id),
            ).fetchone()
        return dict(row) if row is not None else None

    def get_project_ids_for_account(self, account_id: str) -> set[str]:
        """返回该账号有成员身份的所有项目 ID 集合。"""
        with self._conn() as conn:
            rows = conn.execute(
                """
                SELECT DISTINCT pm.project_id
                FROM project_members pm
                JOIN org_members m ON m.id = pm.org_member_id
                WHERE m.account_id = ?
                """,
                (account_id,),
            ).fetchall()
        return {row["project_id"] for row in rows}

    # ---------- 组织邀请 ----------

    def create_invitation(
        self, org_id: str, inviter_account_id: str, invitee_account_id: str
    ) -> dict[str, Any]:
        if inviter_account_id == invitee_account_id:
            raise ValueError("不能邀请自己")
        now = _now()
        invitation = {
            "id": _new_id(),
            "org_id": org_id,
            "inviter_account_id": inviter_account_id,
            "invitee_account_id": invitee_account_id,
            "status": "pending",
            "created_at": now,
            "responded_at": None,
        }
        with self._conn() as conn:
            org = conn.execute("SELECT id FROM organizations WHERE id = ?", (org_id,)).fetchone()
            if org is None:
                raise ValueError("组织不存在")
            if conn.execute(
                "SELECT 1 FROM org_members WHERE org_id = ? AND account_id = ? AND status = 'active'",
                (org_id, inviter_account_id),
            ).fetchone() is None:
                raise ValueError("只有组织成员可以发出邀请")
            if conn.execute(
                "SELECT 1 FROM org_members WHERE org_id = ? AND account_id = ? AND status = 'active'",
                (org_id, invitee_account_id),
            ).fetchone() is not None:
                raise ValueError("对方已经是组织成员")
            existing = conn.execute(
                "SELECT * FROM organization_invitations WHERE org_id = ? AND invitee_account_id = ? AND status = 'pending'",
                (org_id, invitee_account_id),
            ).fetchone()
            if existing is not None:
                return dict(existing)
            conn.execute(
                "INSERT INTO organization_invitations "
                "(id, org_id, inviter_account_id, invitee_account_id, status, created_at, responded_at) "
                "VALUES (?, ?, ?, ?, ?, ?, ?)",
                tuple(invitation.values()),
            )
        return invitation

    def list_invitations_for_account(self, account_id: str) -> list[dict[str, Any]]:
        with self._conn() as conn:
            rows = conn.execute(
                """
                SELECT i.*, o.name AS org_name
                FROM organization_invitations i
                JOIN organizations o ON o.id = i.org_id
                WHERE i.invitee_account_id = ?
                ORDER BY CASE i.status WHEN 'pending' THEN 0 ELSE 1 END, i.created_at DESC
                """,
                (account_id,),
            ).fetchall()
        return [dict(r) for r in rows]

    def list_org_invitations(self, org_id: str, inviter_account_id: str) -> list[dict[str, Any]]:
        if self.account_member(org_id, inviter_account_id) is None:
            raise ValueError("只有组织成员可以查看邀请")
        with self._conn() as conn:
            rows = conn.execute(
                "SELECT * FROM organization_invitations WHERE org_id = ? ORDER BY created_at DESC",
                (org_id,),
            ).fetchall()
        return [dict(r) for r in rows]

    def respond_invitation(
        self, invitation_id: str, account: dict[str, Any], accept: bool
    ) -> dict[str, Any]:
        now = _now()
        with self._conn() as conn:
            row = conn.execute(
                "SELECT * FROM organization_invitations WHERE id = ?", (invitation_id,)
            ).fetchone()
            if row is None:
                raise ValueError("邀请不存在")
            if row["invitee_account_id"] != account["id"]:
                raise ValueError("这不是发给你的邀请")
            if row["status"] != "pending":
                return dict(row)
            status = "accepted" if accept else "declined"
            conn.execute(
                "UPDATE organization_invitations SET status = ?, responded_at = ? WHERE id = ?",
                (status, now, invitation_id),
            )
            if accept:
                member = conn.execute(
                    "SELECT * FROM org_members WHERE org_id = ? AND account_id = ?",
                    (row["org_id"], account["id"]),
                ).fetchone()
                if member is None:
                    conn.execute(
                        """
                        INSERT INTO org_members
                            (id, org_id, kind, name, participant_id, org_role, materials, status, created_at, account_id)
                        VALUES (?, ?, 'human', ?, NULL, '', '', 'active', ?, ?)
                        """,
                        (_new_id(), row["org_id"], account["display_name"], now, account["id"]),
                    )
                else:
                    conn.execute(
                        "UPDATE org_members SET name = ?, status = 'active' WHERE id = ?",
                        (account["display_name"], member["id"]),
                    )
            result = conn.execute(
                "SELECT * FROM organization_invitations WHERE id = ?", (invitation_id,)
            ).fetchone()
        return dict(result)

    # ---------- 私信 ----------

    def get_or_create_direct_conversation(self, account_a_id: str, account_b_id: str) -> dict[str, Any]:
        if account_a_id == account_b_id:
            raise ValueError("不能和自己发起私信")
        first, second = sorted((account_a_id, account_b_id))
        now = _now()
        with self._conn() as conn:
            row = conn.execute(
                "SELECT * FROM direct_conversations WHERE account_a_id = ? AND account_b_id = ?",
                (first, second),
            ).fetchone()
            if row is not None:
                return dict(row)
            conversation = {
                "id": _new_id(),
                "account_a_id": first,
                "account_b_id": second,
                "status": "active",
                "created_at": now,
            }
            conn.execute(
                "INSERT INTO direct_conversations (id, account_a_id, account_b_id, status, created_at) VALUES (?, ?, ?, ?, ?)",
                tuple(conversation.values()),
            )
        return conversation

    def get_direct_conversation(self, conversation_id: str) -> dict[str, Any] | None:
        with self._conn() as conn:
            row = conn.execute(
                "SELECT * FROM direct_conversations WHERE id = ?", (conversation_id,)
            ).fetchone()
        return dict(row) if row is not None else None

    def list_direct_conversations(self, account_id: str) -> list[dict[str, Any]]:
        with self._conn() as conn:
            rows = conn.execute(
                """
                SELECT * FROM direct_conversations
                WHERE account_a_id = ? OR account_b_id = ?
                ORDER BY created_at DESC
                """,
                (account_id, account_id),
            ).fetchall()
        return [dict(r) for r in rows]

    @staticmethod
    def direct_other_account(conversation: dict[str, Any], account_id: str) -> str | None:
        if conversation.get("account_a_id") == account_id:
            return conversation.get("account_b_id")
        if conversation.get("account_b_id") == account_id:
            return conversation.get("account_a_id")
        return None

    def add_org_member(
        self,
        org_id: str,
        kind: str,
        name: str,
        participant_id: str | None = None,
        org_role: str = "",
        account_id: str | None = None,
    ) -> dict[str, Any]:
        member = {
            "id": _new_id(),
            "org_id": org_id,
            "kind": kind,
            "name": name,
            "participant_id": participant_id,
            "account_id": account_id,
            "org_role": org_role,
            "materials": "",
            "status": "active",
            "created_at": _now(),
        }
        with self._conn() as conn:
            conn.execute(
                """
                INSERT INTO org_members
                    (id, org_id, kind, name, participant_id, org_role, materials, status, created_at, account_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                """,
                (
                    member["id"],
                    org_id,
                    kind,
                    name,
                    participant_id,
                    org_role,
                    "",
                    member["status"],
                    member["created_at"],
                    account_id,
                ),
            )
        return member

    def ensure_account_member(self, org_id: str, account: dict[str, Any]) -> dict[str, Any]:
        """Link by stable account ID; never claim an existing unlinked name."""
        with self._conn() as conn:
            row = conn.execute("SELECT * FROM org_members WHERE org_id = ? AND account_id = ?",
                               (org_id, account["id"])).fetchone()
        if row is not None:
            return dict(row)
        try:
            return self.add_org_member(org_id, "human", account["display_name"], account_id=account["id"])
        except sqlite3.IntegrityError:
            # Concurrent first messages may both attempt to create the same projection.
            with self._conn() as conn:
                row = conn.execute("SELECT * FROM org_members WHERE org_id = ? AND account_id = ?",
                                   (org_id, account["id"])).fetchone()
            if row is None:
                raise
            return dict(row)

    def update_org_member(
        self,
        member_id: str,
        *,
        org_role: str | None = None,
        materials: str | None = None,
    ) -> dict[str, Any] | None:
        """更新成员的组织职位/职责或专属资料；返回更新后的成员。"""
        with self._conn() as conn:
            if org_role is not None:
                conn.execute(
                    "UPDATE org_members SET org_role = ? WHERE id = ?",
                    (org_role, member_id),
                )
            if materials is not None:
                conn.execute(
                    "UPDATE org_members SET materials = ? WHERE id = ?",
                    (materials, member_id),
                )
            row = conn.execute(
                "SELECT * FROM org_members WHERE id = ?", (member_id,)
            ).fetchone()
        return dict(row) if row is not None else None

    def get_member(self, member_id: str) -> dict[str, Any] | None:
        """成员 + 所属组织名 + 所在项目（含负责位置）。"""
        with self._conn() as conn:
            member = conn.execute(
                "SELECT * FROM org_members WHERE id = ?", (member_id,)
            ).fetchone()
            if member is None:
                return None
            result = dict(member)
            org = conn.execute(
                "SELECT name FROM organizations WHERE id = ?", (result["org_id"],)
            ).fetchone()
            result["org_name"] = org["name"] if org else ""
            projects = conn.execute(
                """
                SELECT p.id, p.title, pm.position
                FROM projects p
                JOIN project_members pm ON pm.project_id = p.id
                WHERE pm.org_member_id = ?
                ORDER BY p.created_at
                """,
                (member_id,),
            ).fetchall()
            result["projects"] = [dict(r) for r in projects]
        return result

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

    def set_project_member_position(
        self, project_id: str, org_member_id: str, position: str
    ) -> None:
        """设定成员在项目内负责的位置/职责（普通软件字段）。"""
        with self._conn() as conn:
            conn.execute(
                "UPDATE project_members SET position = ? WHERE project_id = ? AND org_member_id = ?",
                (position, project_id, org_member_id),
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

    # ---------- 会话（普通软件对象，不是 Space） ----------

    def ensure_project_channel(self, project_id: str) -> dict[str, Any]:
        """项目的默认频道；不存在则创建。旧 occurrence 不迁移，由读取侧投影。"""
        with self._conn() as conn:
            row = conn.execute(
                "SELECT * FROM conversations WHERE project_id = ? AND is_default = 1 LIMIT 1",
                (project_id,),
            ).fetchone()
            if row is not None:
                return dict(row)
            project = conn.execute(
                "SELECT org_id FROM projects WHERE id = ?", (project_id,)
            ).fetchone()
            if project is None:
                raise ValueError("项目不存在")
            conversation = {
                "id": _new_id(),
                "org_id": project["org_id"],
                "project_id": project_id,
                "kind": "channel",
                "title": "项目频道",
                "is_default": 1,
                "status": "active",
                "created_at": _now(),
            }
            conn.execute(
                """
                INSERT INTO conversations
                    (id, org_id, project_id, kind, title, is_default, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                """,
                (
                    conversation["id"],
                    conversation["org_id"],
                    conversation["project_id"],
                    conversation["kind"],
                    conversation["title"],
                    conversation["is_default"],
                    conversation["status"],
                    conversation["created_at"],
                ),
            )
        return conversation

    def list_conversations(self, project_id: str) -> list[dict[str, Any]]:
        with self._conn() as conn:
            rows = conn.execute(
                "SELECT * FROM conversations WHERE project_id = ? ORDER BY is_default DESC, created_at",
                (project_id,),
            ).fetchall()
        return [dict(r) for r in rows]

    def get_conversation(self, conversation_id: str) -> dict[str, Any] | None:
        with self._conn() as conn:
            row = conn.execute(
                "SELECT * FROM conversations WHERE id = ?", (conversation_id,)
            ).fetchone()
        return dict(row) if row is not None else None

    def create_conversation(
        self,
        org_id: str,
        project_id: str | None,
        title: str,
        kind: str = "channel",
    ) -> dict[str, Any]:
        if kind not in ("channel", "dm", "group"):
            raise ValueError("会话类型只支持 channel / dm / group")
        conversation = {
            "id": _new_id(),
            "org_id": org_id,
            "project_id": project_id,
            "kind": kind,
            "title": title.strip() or "未命名会话",
            "is_default": 0,
            "status": "active",
            "created_at": _now(),
        }
        with self._conn() as conn:
            conn.execute(
                """
                INSERT INTO conversations
                    (id, org_id, project_id, kind, title, is_default, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                """,
                (
                    conversation["id"],
                    conversation["org_id"],
                    conversation["project_id"],
                    conversation["kind"],
                    conversation["title"],
                    conversation["is_default"],
                    conversation["status"],
                    conversation["created_at"],
                ),
            )
        return conversation

    def list_conversation_members(self, conversation_id: str) -> list[dict[str, Any]]:
        with self._conn() as conn:
            rows = conn.execute(
                """
                SELECT m.* FROM org_members m
                JOIN conversation_members cm ON cm.org_member_id = m.id
                WHERE cm.conversation_id = ? ORDER BY m.created_at
                """,
                (conversation_id,),
            ).fetchall()
        return [dict(r) for r in rows]

    def add_conversation_member(self, conversation_id: str, org_member_id: str) -> None:
        with self._conn() as conn:
            conn.execute(
                "INSERT OR IGNORE INTO conversation_members (conversation_id, org_member_id) VALUES (?, ?)",
                (conversation_id, org_member_id),
            )

    # ---------- 决定 ----------

    def list_decisions(self, project_id: str) -> list[dict[str, Any]]:
        with self._conn() as conn:
            rows = conn.execute(
                "SELECT * FROM decisions WHERE project_id = ? ORDER BY decided_at DESC",
                (project_id,),
            ).fetchall()
        return [self._decode_decision(r) for r in rows]

    def get_decision(self, decision_id: str) -> dict[str, Any] | None:
        with self._conn() as conn:
            row = conn.execute(
                "SELECT * FROM decisions WHERE id = ?", (decision_id,)
            ).fetchone()
        return self._decode_decision(row) if row is not None else None

    @staticmethod
    def _decode_decision(row: sqlite3.Row) -> dict[str, Any]:
        decision = dict(row)
        try:
            decision["source_refs"] = json.loads(decision.get("source_refs") or "[]")
        except (TypeError, ValueError):
            decision["source_refs"] = []
        return decision

    def create_decision(
        self,
        project_id: str,
        content: str,
        *,
        source_refs: list[str] | None = None,
        decided_by: str = "小林",
        status: str = "accepted",
        reopen_condition: str = "",
    ) -> dict[str, Any]:
        if status not in DECISION_STATUSES:
            raise ValueError(f"决定状态只支持 {', '.join(DECISION_STATUSES)}")
        decision = {
            "id": _new_id(),
            "project_id": project_id,
            "content": content,
            "source_refs": json.dumps(list(source_refs or []), ensure_ascii=False),
            "decided_by": decided_by.strip() or "小林",
            "decided_at": _now(),
            "status": status,
            "reopen_condition": reopen_condition,
            "created_at": _now(),
        }
        with self._conn() as conn:
            conn.execute(
                """
                INSERT INTO decisions
                    (id, project_id, content, source_refs, decided_by, decided_at, status, reopen_condition, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                """,
                (
                    decision["id"],
                    decision["project_id"],
                    decision["content"],
                    decision["source_refs"],
                    decision["decided_by"],
                    decision["decided_at"],
                    decision["status"],
                    decision["reopen_condition"],
                    decision["created_at"],
                ),
            )
        return self.get_decision(decision["id"])  # type: ignore[return-value]

    # ---------- 工作项 ----------

    @staticmethod
    def _decode_work_item(row: sqlite3.Row) -> dict[str, Any]:
        item = dict(row)
        try:
            item["source_refs"] = json.loads(item.get("source_refs") or "[]")
        except (TypeError, ValueError):
            item["source_refs"] = []
        return item

    def list_work_items(self, project_id: str) -> list[dict[str, Any]]:
        with self._conn() as conn:
            rows = conn.execute(
                "SELECT * FROM work_items WHERE project_id = ? ORDER BY created_at",
                (project_id,),
            ).fetchall()
        return [self._decode_work_item(r) for r in rows]

    def get_work_item(self, work_item_id: str) -> dict[str, Any] | None:
        with self._conn() as conn:
            row = conn.execute(
                "SELECT * FROM work_items WHERE id = ?", (work_item_id,)
            ).fetchone()
        return self._decode_work_item(row) if row is not None else None

    def create_work_item(
        self,
        project_id: str,
        title: str,
        *,
        description: str = "",
        assignee_member_id: str | None = None,
        source_refs: list[str] | None = None,
        decision_id: str | None = None,
        actor: str = "小林",
    ) -> dict[str, Any]:
        now = _now()
        item = {
            "id": _new_id(),
            "project_id": project_id,
            "title": title.strip(),
            "description": description,
            "status": "open",
            "assignee_member_id": assignee_member_id,
            "source_refs": json.dumps(list(source_refs or []), ensure_ascii=False),
            "decision_id": decision_id,
            "created_at": now,
            "updated_at": now,
        }
        with self._conn() as conn:
            conn.execute(
                """
                INSERT INTO work_items
                    (id, project_id, title, description, status, assignee_member_id, source_refs, decision_id, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                """,
                (
                    item["id"],
                    item["project_id"],
                    item["title"],
                    item["description"],
                    item["status"],
                    item["assignee_member_id"],
                    item["source_refs"],
                    item["decision_id"],
                    item["created_at"],
                    item["updated_at"],
                ),
            )
        self.add_work_item_event(item["id"], "created", item["title"], actor=actor)
        return self.get_work_item(item["id"])  # type: ignore[return-value]

    def update_work_item(
        self,
        work_item_id: str,
        *,
        title: Any = _UNSET,
        description: Any = _UNSET,
        status: Any = _UNSET,
        assignee_member_id: Any = _UNSET,
        decision_id: Any = _UNSET,
        actor: str = "小林",
    ) -> dict[str, Any] | None:
        current = self.get_work_item(work_item_id)
        if current is None:
            return None
        if status is not _UNSET and status not in WORK_ITEM_STATUSES:
            raise ValueError(f"工作项状态只支持 {', '.join(WORK_ITEM_STATUSES)}")
        changes: list[tuple[str, str]] = []
        values: list[Any] = []
        if title is not _UNSET and title is not None and title.strip() != current["title"]:
            changes.append(("title", f"标题：{current['title']} → {title.strip()}"))
            values.append(("title", title.strip()))
        if description is not _UNSET and description is not None and description != current["description"]:
            changes.append(("description", "描述已更新"))
            values.append(("description", description))
        if status is not _UNSET and status is not None and status != current["status"]:
            changes.append(("status", f"状态：{current['status']} → {status}"))
            values.append(("status", status))
        if assignee_member_id is not _UNSET and assignee_member_id != current["assignee_member_id"]:
            changes.append(("assignee", "负责人已变更"))
            values.append(("assignee_member_id", assignee_member_id))
        if decision_id is not _UNSET and decision_id != current["decision_id"]:
            changes.append(("decision", "关联决定已变更"))
            values.append(("decision_id", decision_id))
        if not values:
            return current
        with self._conn() as conn:
            assignments = ", ".join(f"{column} = ?" for column, _ in values)
            conn.execute(
                f"UPDATE work_items SET {assignments}, updated_at = ? WHERE id = ?",
                [value for _, value in values] + [_now(), work_item_id],
            )
        for kind, detail in changes:
            self.add_work_item_event(work_item_id, kind, detail, actor=actor)
        return self.get_work_item(work_item_id)

    def add_work_item_event(
        self, work_item_id: str, kind: str, detail: str = "", *, actor: str = "小林"
    ) -> dict[str, Any]:
        event = {
            "id": _new_id(),
            "work_item_id": work_item_id,
            "kind": kind,
            "detail": detail,
            "actor": actor,
            "created_at": _now(),
        }
        with self._conn() as conn:
            conn.execute(
                """
                INSERT INTO work_item_events (id, work_item_id, kind, detail, actor, created_at)
                VALUES (?, ?, ?, ?, ?, ?)
                """,
                (
                    event["id"],
                    event["work_item_id"],
                    event["kind"],
                    event["detail"],
                    event["actor"],
                    event["created_at"],
                ),
            )
        return event

    def list_work_item_events(self, work_item_id: str) -> list[dict[str, Any]]:
        with self._conn() as conn:
            rows = conn.execute(
                "SELECT * FROM work_item_events WHERE work_item_id = ? ORDER BY created_at",
                (work_item_id,),
            ).fetchall()
        return [dict(r) for r in rows]
