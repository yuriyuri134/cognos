"""活动的当前采用与不可覆盖修订。不是原始发生的新真相源。"""

from __future__ import annotations

import json
import hashlib
import sqlite3
import uuid
from contextlib import contextmanager
from collections.abc import Iterator
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

from activity_program import ProgramError, document_version, execute_program
from activity_acquisition import validate_request


class ActivityConflict(ValueError):
    pass


def _text(value: Any, name: str, *, limit: int = 200_000) -> str:
    if not isinstance(value, str) or len(value) > limit:
        raise ValueError(f"{name} 必须是长度不超过 {limit} 的文字")
    return value


def _body(value: Any, format_name: str) -> Any:
    if format_name not in ("text", "json"):
        raise ValueError("初版只支持 text 与 json 载体")
    if format_name == "text" and not isinstance(value, str):
        raise ValueError("text 文档内容必须是文字")
    try:
        encoded = json.dumps(value, ensure_ascii=False, allow_nan=False)
    except (ValueError, TypeError, RecursionError) as exc:
        raise ValueError("内容不能保存为有限 JSON") from exc
    if len(encoded) > 200_000:
        raise ValueError("文档过长，请按内容拆分")
    return json.loads(encoded)


class ActivityStore:
    def __init__(self, path: Path) -> None:
        self.path = Path(path)
        self.path.parent.mkdir(parents=True, exist_ok=True)
        with self._connect() as conn:
            conn.executescript("""
                CREATE TABLE IF NOT EXISTS activity_revisions (
                    activity_id TEXT NOT NULL,
                    revision INTEGER NOT NULL,
                    project_id TEXT NOT NULL,
                    state_json TEXT NOT NULL,
                    actor TEXT NOT NULL,
                    reason TEXT NOT NULL,
                    created_at TEXT NOT NULL,
                    PRIMARY KEY (activity_id, revision)
                );
                CREATE INDEX IF NOT EXISTS activity_project
                    ON activity_revisions(project_id, activity_id, revision);
                CREATE TABLE IF NOT EXISTS activity_runs (
                    id TEXT PRIMARY KEY,
                    activity_id TEXT NOT NULL,
                    request_id TEXT NOT NULL,
                    request_json TEXT NOT NULL,
                    state_json TEXT NOT NULL,
                    UNIQUE(activity_id, request_id)
                );
                CREATE TABLE IF NOT EXISTS activity_moves (
                    id TEXT PRIMARY KEY,
                    activity_id TEXT NOT NULL,
                    request_id TEXT NOT NULL,
                    proposer TEXT NOT NULL,
                    basis_revision INTEGER NOT NULL,
                    intent TEXT NOT NULL,
                    execution_json TEXT NOT NULL,
                    state_json TEXT NOT NULL,
                    created_at TEXT NOT NULL,
                    occurrence_id TEXT,
                    UNIQUE(activity_id, request_id)
                );
                CREATE INDEX IF NOT EXISTS activity_moves_idx
                    ON activity_moves(activity_id);
                CREATE TABLE IF NOT EXISTS activity_context_snapshots (
                    id TEXT PRIMARY KEY,
                    activity_id TEXT NOT NULL,
                    run_id TEXT NOT NULL,
                    call_index INTEGER NOT NULL,
                    activity_revision INTEGER NOT NULL,
                    context_json TEXT NOT NULL,
                    rendered_prompt TEXT NOT NULL,
                    rendered_prompt_hash TEXT NOT NULL,
                    created_at TEXT NOT NULL,
                    UNIQUE(activity_id, run_id, call_index)
                );
                CREATE INDEX IF NOT EXISTS activity_context_activity
                    ON activity_context_snapshots(activity_id, created_at);
                CREATE INDEX IF NOT EXISTS activity_context_run
                    ON activity_context_snapshots(run_id, call_index);
            """)
            columns = {row["name"] for row in conn.execute("PRAGMA table_info(activity_moves)")}
            if "proposer_participant_id" not in columns:
                conn.execute("ALTER TABLE activity_moves ADD COLUMN proposer_participant_id TEXT")

    @contextmanager
    def _connect(self) -> Iterator[sqlite3.Connection]:
        conn = sqlite3.connect(self.path, timeout=10)
        conn.row_factory = sqlite3.Row
        try:
            with conn:
                yield conn
        finally:
            # sqlite 的事务上下文不会关闭连接；Windows 上否则会锁住活动文件。
            conn.close()

    @staticmethod
    def _decode(row: sqlite3.Row) -> dict[str, Any]:
        return {"id": row["activity_id"], "revision": row["revision"],
                "project_id": row["project_id"], "state": json.loads(row["state_json"]),
                "actor": row["actor"], "reason": row["reason"], "created_at": row["created_at"]}

    @staticmethod
    def _insert(conn: sqlite3.Connection, ident: str, revision: int, project: str,
                state: dict[str, Any], actor: str, reason: str) -> None:
        serialized = json.dumps(state, ensure_ascii=False, allow_nan=False)
        # 全量快照是本次实现选择；达到边界时明确报错，不丢掉旧内容。
        if len(serialized) > 4_000_000:
            raise ValueError("活动超出初版快照范围，需要拆分材料或扩展存储")
        conn.execute("INSERT INTO activity_revisions VALUES (?,?,?,?,?,?,?)",
                     (ident, revision, project, serialized, actor, reason,
                      datetime.now(timezone.utc).isoformat()))

    def create(self, project_id: str, title: str = "", *, actor: str = "小林") -> dict[str, Any]:
        title = _text(title, "标题", limit=200).strip() or "未命名活动"
        ident = uuid.uuid4().hex
        with self._connect() as conn:
            self._insert(conn, ident, 1, project_id,
                         {"title": title, "focus": "", "documents": {}, "adopted": None},
                         actor, "创建活动")
        return self.get(ident)  # type: ignore[return-value]

    def get(self, ident: str, revision: int | None = None) -> dict[str, Any] | None:
        with self._connect() as conn:
            if revision is None:
                row = conn.execute("SELECT * FROM activity_revisions WHERE activity_id=? ORDER BY revision DESC LIMIT 1", (ident,)).fetchone()
            else:
                row = conn.execute("SELECT * FROM activity_revisions WHERE activity_id=? AND revision=?", (ident, revision)).fetchone()
        return self._decode(row) if row else None

    def list(self, project_id: str) -> list[dict[str, Any]]:
        with self._connect() as conn:
            rows = conn.execute("""SELECT a.* FROM activity_revisions a
                JOIN (SELECT activity_id, MAX(revision) r FROM activity_revisions
                      WHERE project_id=? GROUP BY activity_id) h
                ON a.activity_id=h.activity_id AND a.revision=h.r ORDER BY a.created_at DESC""", (project_id,)).fetchall()
        return [{"id": row["activity_id"], "revision": row["revision"],
                 "title": json.loads(row["state_json"])["title"], "created_at": row["created_at"]} for row in rows]

    def find_by_source(self, project_id: str, source_ref: dict[str, Any]) -> dict[str, Any] | None:
        """Return the current activity for an exact immutable source reference."""
        with self._connect() as conn:
            rows = conn.execute(
                """SELECT a.* FROM activity_revisions a
                JOIN (SELECT activity_id, MAX(revision) r FROM activity_revisions
                      WHERE project_id=? GROUP BY activity_id) h
                ON a.activity_id=h.activity_id AND a.revision=h.r
                ORDER BY a.created_at DESC""", (project_id,)).fetchall()
        for row in rows:
            state = json.loads(row["state_json"])
            if any(document.get("source_ref") == source_ref
                   for document in state.get("documents", {}).values()):
                return self._decode(row)
        return None

    def history(self, ident: str) -> list[dict[str, Any]]:
        with self._connect() as conn:
            rows = conn.execute("SELECT revision, actor, reason, created_at FROM activity_revisions WHERE activity_id=? ORDER BY revision", (ident,)).fetchall()
        return [dict(row) for row in rows]

    def begin_run(self, activity_id: str, request_id: str, request: dict[str, Any]) -> tuple[dict[str, Any], bool]:
        """网络重试只返回同一执行；不会因重试重放已发生的修改。"""
        _text(request_id, "请求标识", limit=100)
        if not request_id:
            raise ValueError("请求标识不能为空")
        encoded = json.dumps(request, ensure_ascii=False, sort_keys=True)
        with self._connect() as conn:
            conn.execute("BEGIN IMMEDIATE")
            previous = conn.execute("SELECT * FROM activity_runs WHERE activity_id=? AND request_id=?", (activity_id, request_id)).fetchone()
            if previous:
                if previous["request_json"] != encoded:
                    raise ActivityConflict("同一请求标识不能用于不同内容")
                return json.loads(previous["state_json"]), False
            result = {"id": uuid.uuid4().hex, "activity_id": activity_id, "request_id": request_id,
                      "status": "running", "events": [], "input_revision": request["revision"]}
            conn.execute("INSERT INTO activity_runs VALUES (?,?,?,?,?)", (
                result["id"], activity_id, request_id, encoded, json.dumps(result, ensure_ascii=False)))
        return result, True

    def save_run(self, run: dict[str, Any]) -> None:
        with self._connect() as conn:
            cursor = conn.execute("UPDATE activity_runs SET state_json=? WHERE id=?", (
                json.dumps(run, ensure_ascii=False, allow_nan=False), run["id"]))
            if cursor.rowcount != 1:
                raise KeyError(run["id"])

    def runs(self, ident: str) -> list[dict[str, Any]]:
        with self._connect() as conn:
            rows = conn.execute("SELECT state_json FROM activity_runs WHERE activity_id=? ORDER BY rowid", (ident,)).fetchall()
        return [json.loads(row["state_json"]) for row in rows]

    @staticmethod
    def _decode_context(row: sqlite3.Row) -> dict[str, Any]:
        return {"id": row["id"], "activity_id": row["activity_id"],
                "run_id": row["run_id"], "call_index": row["call_index"],
                "activity_revision": row["activity_revision"],
                "context": json.loads(row["context_json"]),
                "rendered_prompt": row["rendered_prompt"],
                "rendered_prompt_hash": row["rendered_prompt_hash"],
                "created_at": row["created_at"]}

    def save_context_snapshot(self, *, activity_id: str, run_id: str, call_index: int,
                              activity_revision: int, context: dict[str, Any],
                              rendered_prompt: str,
                              rendered_prompt_hash: str | None = None) -> dict[str, Any]:
        """Append one immutable context snapshot; retries are idempotent."""
        if type(call_index) is not int or call_index < 0:
            raise ValueError("调用序号必须是非负整数")
        if type(activity_revision) is not int or activity_revision < 1:
            raise ValueError("活动修订必须是正整数")
        if not isinstance(context, dict):
            raise ValueError("上下文快照必须是对象")
        if not isinstance(rendered_prompt, str):
            raise ValueError("渲染提示必须是文字")
        encoded_context = json.dumps(context, ensure_ascii=False, allow_nan=False,
                                     sort_keys=True)
        digest = hashlib.sha256(rendered_prompt.encode("utf-8")).hexdigest()
        if rendered_prompt_hash is not None and rendered_prompt_hash != digest:
            raise ValueError("上下文提示 hash 不匹配")
        with self._connect() as conn:
            conn.execute("BEGIN IMMEDIATE")
            previous = conn.execute(
                "SELECT * FROM activity_context_snapshots WHERE activity_id=? AND run_id=? AND call_index=?",
                (activity_id, run_id, call_index)).fetchone()
            if previous:
                if (previous["context_json"] != encoded_context or
                        previous["rendered_prompt"] != rendered_prompt or
                        previous["activity_revision"] != activity_revision):
                    raise ActivityConflict("同一调用序号不能用于不同上下文")
                return self._decode_context(previous)
            ident = uuid.uuid4().hex
            conn.execute(
                "INSERT INTO activity_context_snapshots "
                "(id, activity_id, run_id, call_index, activity_revision, context_json, "
                "rendered_prompt, rendered_prompt_hash, created_at) VALUES (?,?,?,?,?,?,?,?,?)",
                (ident, activity_id, run_id, call_index, activity_revision, encoded_context,
                 rendered_prompt, digest, datetime.now(timezone.utc).isoformat()))
            created = conn.execute("SELECT * FROM activity_context_snapshots WHERE id=?",
                                  (ident,)).fetchone()
        return self._decode_context(created)

    def get_context_snapshot(self, snapshot_id: str) -> dict[str, Any] | None:
        with self._connect() as conn:
            row = conn.execute("SELECT * FROM activity_context_snapshots WHERE id=?",
                               (snapshot_id,)).fetchone()
        return self._decode_context(row) if row else None

    def list_context_snapshots(self, activity_id: str, *, run_id: str | None = None,
                               limit: int = 100) -> list[dict[str, Any]]:
        if type(limit) is not int or not 1 <= limit <= 500:
            raise ValueError("快照数量必须在 1 至 500 之间")
        with self._connect() as conn:
            if run_id is None:
                rows = conn.execute(
                    "SELECT * FROM activity_context_snapshots WHERE activity_id=? "
                    "ORDER BY created_at, call_index LIMIT ?", (activity_id, limit)).fetchall()
            else:
                rows = conn.execute(
                    "SELECT * FROM activity_context_snapshots WHERE activity_id=? AND run_id=? "
                    "ORDER BY call_index LIMIT ?", (activity_id, run_id, limit)).fetchall()
        return [self._decode_context(row) for row in rows]

    @staticmethod
    def _decode_move(row: sqlite3.Row) -> dict[str, Any]:
        return {"id": row["id"], "activity_id": row["activity_id"], "request_id": row["request_id"],
                "proposer": row["proposer"], "basis_revision": row["basis_revision"],
                "proposer_participant_id": row["proposer_participant_id"],
                "intent": row["intent"], "execution": json.loads(row["execution_json"]),
                "state": json.loads(row["state_json"]), "created_at": row["created_at"],
                "occurrence_id": row["occurrence_id"]}

    def create_move(self, activity_id: str, request_id: str, *, proposer: str,
                    proposer_participant_id: str | None = None,
                    basis_revision: int, intent: str, execution: list[dict[str, Any]],
                    occurrence_id: str | None = None) -> tuple[dict[str, Any], bool]:
        """幂等创建 Move：同一 request_id 只创建一次，重试不重复执行。"""
        _text(request_id, "请求标识", limit=100)
        if not request_id:
            raise ValueError("请求标识不能为空")
        _text(proposer, "提出者", limit=200)
        _text(intent, "意图", limit=20_000)
        if type(basis_revision) is not int or basis_revision < 1:
            raise ValueError("必须指定所依据的理解版本")
        if not isinstance(execution, list):
            raise ValueError("执行请求必须是步骤列表")
        encoded_execution = json.dumps(execution, ensure_ascii=False, sort_keys=True)
        with self._connect() as conn:
            conn.execute("BEGIN IMMEDIATE")
            previous = conn.execute("SELECT * FROM activity_moves WHERE activity_id=? AND request_id=?",
                                    (activity_id, request_id)).fetchone()
            if previous:
                if previous["execution_json"] != encoded_execution or previous["intent"] != intent:
                    raise ActivityConflict("同一请求标识不能用于不同内容")
                return self._decode_move(previous), False
            ident = uuid.uuid4().hex
            state = {"status": "proposed", "results": []}
            conn.execute("""INSERT INTO activity_moves
                (id, activity_id, request_id, proposer, proposer_participant_id, basis_revision,
                 intent, execution_json, state_json, created_at, occurrence_id)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)""", (
                ident, activity_id, request_id, proposer, proposer_participant_id, basis_revision,
                intent, encoded_execution, json.dumps(state, ensure_ascii=False),
                datetime.now(timezone.utc).isoformat(), occurrence_id))
            created = conn.execute("SELECT * FROM activity_moves WHERE id=?", (ident,)).fetchone()
        return self._decode_move(created), True

    def get_move(self, move_id: str) -> dict[str, Any] | None:
        with self._connect() as conn:
            row = conn.execute("SELECT * FROM activity_moves WHERE id=?", (move_id,)).fetchone()
        return self._decode_move(row) if row else None

    def list_moves(self, activity_id: str) -> list[dict[str, Any]]:
        with self._connect() as conn:
            rows = conn.execute("SELECT * FROM activity_moves WHERE activity_id=? ORDER BY rowid", (activity_id,)).fetchall()
        return [self._decode_move(row) for row in rows]

    def save_move(self, move: dict[str, Any]) -> None:
        with self._connect() as conn:
            cursor = conn.execute("UPDATE activity_moves SET state_json=?, occurrence_id=? WHERE id=?", (
                json.dumps(move["state"], ensure_ascii=False, allow_nan=False),
                move.get("occurrence_id"), move["id"]))
            if cursor.rowcount != 1:
                raise KeyError(move["id"])

    def change(self, ident: str, expected_revision: int, operations: list[dict[str, Any]],
               *, actor: str, reason: str = "", allow_sources: bool = False,
               occurrence_id: str | None = None, source_author: str | None = None) -> dict[str, Any]:
        if type(expected_revision) is not int or expected_revision < 1:
            raise ValueError("必须指定所依据的活动修订")
        if not isinstance(operations, list) or not 1 <= len(operations) <= 40:
            raise ValueError("一次修改需要 1 至 40 个操作")
        _text(actor, "执行者", limit=200)
        _text(reason, "修改理由", limit=4000)
        with self._connect() as conn:
            # 核对和写入同一事务；不在模型调用期间持有数据库锁。
            conn.execute("BEGIN IMMEDIATE")
            row = conn.execute("SELECT * FROM activity_revisions WHERE activity_id=? ORDER BY revision DESC LIMIT 1", (ident,)).fetchone()
            if row is None:
                raise KeyError(ident)
            if row["revision"] != expected_revision:
                raise ActivityConflict("活动已被修改，请读取当前版本后重新处理")
            state = json.loads(row["state_json"])
            for operation in operations:
                self._apply(state, operation, actor=actor, allow_sources=allow_sources,
                            occurrence_id=occurrence_id, source_author=source_author)
            self._insert(conn, ident, expected_revision + 1, row["project_id"], state, actor, reason)
            created = conn.execute("SELECT * FROM activity_revisions WHERE activity_id=? AND revision=?", (ident, expected_revision + 1)).fetchone()
        return self._decode(created)

    @staticmethod
    def _apply(state: dict[str, Any], operation: dict[str, Any], *, actor: str, allow_sources: bool,
               occurrence_id: str | None = None, source_author: str | None = None) -> None:
        if not isinstance(operation, dict):
            raise ValueError("修改操作必须是对象")
        action = operation.get("action")
        if action == "add_document":
            ident = _text(operation.get("id", uuid.uuid4().hex), "文档标识", limit=100)
            if not ident or ident in state["documents"]:
                raise ValueError("文档标识为空或已存在")
            if len(state["documents"]) >= 100:
                raise ValueError("活动已达到初版文档数量限制")
            title = _text(operation.get("title", "未命名材料"), "文档标题", limit=200)
            format_name = operation.get("format", "text")
            immutable = operation.get("immutable", False)
            if type(immutable) is not bool or (immutable and not allow_sources):
                raise ValueError("只有实际导入通路可以创建保留原文的材料")
            state["documents"][ident] = {
                "title": title, "format": format_name, "immutable": immutable,
                "source_ref": operation.get("source_ref"),
                "versions": [{"body": _body(operation.get("body"), format_name), "actor": actor,
                              "occurrence_id": occurrence_id, "source_author": source_author}],
            }
        elif action == "revise_document":
            ident = operation.get("document")
            if not isinstance(ident, str) or ident not in state["documents"]:
                raise ValueError("待修改文档不存在")
            document = state["documents"][ident]
            if document["immutable"]:
                raise ValueError("原始材料不能覆盖；请建立新的可编辑文档")
            document["versions"].append({"body": _body(operation.get("body"), document["format"]), "actor": actor,
                                         "occurrence_id": occurrence_id})
        elif action in ("adopt", "adopt_source"):
            found = document_version(state, operation.get("document"), operation.get("version"))
            if found["format"] != "json":
                raise ValueError("需要采用一份 JSON 组合定义")
            # 能执行才采用；后续材料改变仍可能导致失败，运行时必须保留失败事实。
            compiled = execute_program(state, {"op": "call", "document": found["id"], "version": found["version"]})
            if action == "adopt_source":
                validate_request(compiled.value)
            slot = "source_adopted" if action == "adopt_source" else "adopted"
            state[slot] = {"document": found["id"], "version": found["version"],
                                "program_versions": compiled.program_versions}
        elif action == "release":
            state["adopted"] = None
        elif action == "release_source":
            state["source_adopted"] = None
        elif action == "set_situation":
            text = _text(operation.get("text", ""), "当前理解", limit=50_000)
            refs = operation.get("refs", [])
            if not isinstance(refs, list) or len(refs) > 40:
                raise ValueError("理解引用至多 40 项")
            validated: list[dict[str, Any]] = []
            for ref in refs:
                if not isinstance(ref, dict):
                    raise ValueError("理解引用必须是对象")
                # 引用只是来源关联，不因引用而自动成立；版本必须在快照内真实存在。
                found = document_version(state, ref.get("document"), ref.get("version"))
                validated.append({"document": found["id"], "version": found["version"],
                                  "excerpt": _text(ref.get("excerpt", ""), "引用片段", limit=10_000),
                                  "note": _text(ref.get("note", ""), "引用说明", limit=2_000)})
            state["situation"] = {"text": text, "refs": validated}
            if operation.get("formation_projection") is True:
                state["situation"]["formation_projection"] = True
        elif action == "set_focus":
            state["focus"] = _text(operation.get("text", ""), "当前关注", limit=10_000)
        elif action == "rename":
            state["title"] = _text(operation.get("title"), "标题", limit=200).strip() or "未命名活动"
        else:
            raise ValueError(f"尚不支持的修改：{action!r}")


def material_catalog(state: dict[str, Any]) -> list[dict[str, Any]]:
    """任何采用方式都不裁剪这份目录；未选材料仍能被重新取得。"""
    return [{"id": ident, "title": document["title"], "format": document["format"],
             "version": len(document["versions"]), "immutable": document["immutable"],
             "source_author": document["versions"][-1].get("source_author"),
             "occurrence_id": document["versions"][-1].get("occurrence_id")}
            for ident, document in state["documents"].items()]
