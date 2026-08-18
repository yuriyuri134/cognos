"""Cognos 第一网页竖切：一个真实、可恢复的沟通发生场。"""

from __future__ import annotations

import asyncio
import os
import sys
from dataclasses import dataclass
from pathlib import Path
from typing import Any

from fastapi import FastAPI, HTTPException
from fastapi.responses import FileResponse
from fastapi.staticfiles import StaticFiles
from pydantic import BaseModel, Field
from typing import Any, List


WEB_DIR = Path(__file__).resolve().parent
PROJECT_DIR = WEB_DIR.parent
PHASE0_DIR = PROJECT_DIR / "phase0"
FRONTEND_DIR = WEB_DIR / "frontend"
sys.path.insert(0, str(PHASE0_DIR))

from codex_runtime import (
    CodexRuntimeAdapter,
    JsonThreadStore,
    RuntimeAdapter,
    RuntimeUnavailableError,
)
from gemini_runtime import GeminiRuntimeAdapter, GeminiStateStore
from deepseek_runtime import DeepSeekRuntimeAdapter, DeepSeekStateStore, load_deepseek_config
from history import AppendOnlyHistory, HistoryCorruptionError
from handoff.envelope import build_envelope, write_envelope
from org_api import create_org_router
from org_store import OrgStore


def _load_env_file(path: Path) -> None:
    if not path.exists():
        return
    try:
        for line in path.read_text(encoding="utf-8").splitlines():
            line = line.strip()
            if not line or line.startswith("#") or "=" not in line:
                continue
            key, _, val = line.partition("=")
            key = key.strip()
            val = val.strip().strip("'\"")
            if key and key not in os.environ:
                os.environ[key] = val
    except Exception:
        pass


_load_env_file(WEB_DIR / ".env")


@dataclass
class Participant:
    id: str
    name: str
    model_name: str
    runtime: RuntimeAdapter
    description: str = ""

    def get_thread_id(self) -> str | None:
        if hasattr(self.runtime, "state_store") and hasattr(self.runtime.state_store, "load"):
            st = self.runtime.state_store.load()
            if isinstance(st, str):
                return st
            elif hasattr(st, "thread_id"):
                return st.thread_id
        return None

    def to_dict(self) -> dict[str, Any]:
        return {
            "id": self.id,
            "name": self.name,
            "model_name": self.model_name,
            "thread_id": self.get_thread_id(),
            "description": self.description,
        }


@dataclass(frozen=True)
class AppConfig:
    history_path: Path
    thread_state_dir: Path
    codex_cwd: Path
    participant_instructions_path: Path
    gemini_api_key: str | None = None
    gemini_model: str = "gemini-3.7-flash"
    handoff_dir: Path | None = None
    org_db_path: Path | None = None


class SendMessageRequest(BaseModel):
    content: str
    target: str | None = None
    quoted_occurrence_id: str | None = None


class HandoffCreateRequest(BaseModel):
    instruction: str = Field(..., description="小林的交接指令")
    target_agent: str = Field(..., description="接收任务的外部代理，如 'Antigravity' 或 'DSH'")
    working_directory: str | None = None
    occurrence_refs: List[str] = Field(
        default_factory=list,
        description="发生场中的原始 occurrence ID 列表（E1 occurrence_refs）",
    )
    source_refs: List[str] = Field(
        default_factory=list,
        description="可选的额外来源引用；兼容旧客户端：无 occurrence_refs 时视为 occurrence ID",
    )


class HandoffResultRequest(BaseModel):
    handoff_id: str = Field(..., description="对应的 Handoff 发生 ID")
    agent_name: str = Field(..., description="回传结果的代理名称，如 'Antigravity' 或 'DSH'")
    changed_files: List[str] = Field(default_factory=list)
    commands_run: List[str] = Field(default_factory=list)
    agent_response: str = Field(..., description="代理的回传总结或输出")
    errors: List[str] = Field(default_factory=list)
    session_ref: dict[str, Any] | None = Field(default=None, description="DSH session_id 与 session_log_path 引用")
    related_occurrence_ids: List[str] = Field(default_factory=list, description="生成 Handoff 时引用的 occurrence ID")
    git_provenance: dict[str, Any] | None = Field(default=None, description="git status/diff/HEAD 机械采集结果")
    test_provenance: dict[str, Any] | None = Field(default=None, description="真实测试命令与输出引用")


class ConversationService:
    """串行组织一次发言与特定参与者的 Runtime turn，支持同一发生场内多参与者共在。"""

    def __init__(
        self,
        history: AppendOnlyHistory,
        participants: dict[str, Participant],
        default_target_id: str | None = None,
    ) -> None:
        self.history = history
        self.participants = participants
        self.default_target_id = default_target_id or (next(iter(participants.keys())) if participants else "gemini")
        self._send_lock = asyncio.Lock()

    def resolve_target(self, content: str, requested_target: str | None) -> tuple[str, str]:
        """解析受话参与者。支持 @Codex / @Gemini 语法或显式请求参数。"""
        stripped = content.strip()
        for p_id, p in self.participants.items():
            triggers = [f"@{p.name.lower()}", f"@{p_id.lower()}"]
            for trig in triggers:
                if stripped.lower().startswith(trig):
                    rest = stripped[len(trig):].lstrip(" :,，：\n")
                    return p_id, rest or stripped

        if requested_target and requested_target.lower() in self.participants:
            return requested_target.lower(), stripped

        return self.default_target_id, stripped

    async def send(
        self,
        content: str,
        target: str | None = None,
        quoted_occurrence_id: str | None = None,
        project_id: str | None = None,
    ) -> dict[str, Any]:
        if not content.strip():
            raise ValueError("消息不能为空")
        if len(content) > 100_000:
            raise ValueError("单条消息暂不能超过 100000 个字符")

        target_id, target_content = self.resolve_target(content, target)
        if target_id not in self.participants:
            raise ValueError(f"未找到指定的参与者：{target_id}")

        participant = self.participants[target_id]

        quoted_record = None
        if quoted_occurrence_id:
            quoted_record = self.history.get_by_id(quoted_occurrence_id)

        # 显式构造呈现给目标参与者的输入，不伪造原生历史，也不污染 system prompt
        if quoted_record:
            q_author = quoted_record.get("author", "未知参与者")
            q_id = quoted_record.get("id", "")
            q_content = quoted_record.get("content", "")
            actual_input = (
                f"[来自当前发生场的已有发生]\n"
                f"occurrence_id: {q_id}\n"
                f"author: {q_author}\n\n"
                f"{q_content}\n"
                f"[/发生]\n\n"
                f"小林：\n"
                f"{target_content or content}"
            )
        else:
            actual_input = target_content or content

        async with self._send_lock:
            user_record = self.history.append_message(
                author="小林",
                content=content,
                target=participant.name,
                target_participant_id=participant.id,
                quoted_occurrence_id=quoted_occurrence_id if quoted_record else None,
                project_id=project_id,
            )
            # 发送给指定参与者的独立运行时，保持该参与者自己的连续性
            reply = await participant.runtime.send(actual_input)

            runtime_ref: dict[str, Any] = {
                "runtime": participant.id,
                "thread_id": reply.thread_id,
                "turn_id": reply.turn_id,
            }
            if getattr(reply, "model_used", None):
                runtime_ref["model"] = reply.model_used

            ai_record = self.history.append_message(
                author=participant.name,
                author_participant_id=participant.id,
                content=reply.text,
                in_reply_to=user_record["id"],
                quoted_occurrence_id=quoted_occurrence_id if quoted_record else None,
                project_id=project_id,
                runtime_ref=runtime_ref,
            )
            return {
                "user": user_record,
                "reply": ai_record,
                "target": participant.to_dict(),
            }


def default_config() -> AppConfig:
    vault_root = Path(
        os.environ.get(
            "COGNOS_VAULT_PATH",
            str(Path.home() / "Documents" / "Obsidian Vault"),
        )
    ).resolve()

    thread_state_dir = Path(
        os.environ.get(
            "COGNOS_THREAD_STATE_DIR",
            str(WEB_DIR / ".state"),
        )
    ).resolve()
    codex_cwd = Path(os.environ.get("COGNOS_CODEX_CWD", str(PROJECT_DIR))).resolve()

    return AppConfig(
        history_path=vault_root
        / "10 Projects"
        / "Cognos"
        / "运行数据"
        / "第一发生场.jsonl",
        thread_state_dir=thread_state_dir,
        codex_cwd=codex_cwd,
        participant_instructions_path=WEB_DIR / "participant_instructions.md",
        gemini_api_key=os.environ.get("GEMINI_API_KEY"),
        gemini_model=os.environ.get("GEMINI_MODEL", "gemini-3.7-flash"),
        handoff_dir=Path(
            os.environ.get(
                "COGNOS_HANDOFF_DIR",
                str(thread_state_dir / "handoffs"),
            )
        ).resolve(),
        org_db_path=Path(
            os.environ.get(
                "COGNOS_ORG_DB_PATH",
                str(thread_state_dir / "org.db"),
            )
        ).resolve(),
    )


def _read_instructions(path: Path) -> str:
    try:
        return path.read_text(encoding="utf-8")
    except OSError as exc:
        raise RuntimeError(f"无法读取当前参与说明：{path}") from exc


def create_app(
    config: AppConfig | None = None,
    *,
    runtime: RuntimeAdapter | None = None,
    participants: dict[str, Participant] | None = None,
) -> FastAPI:
    config = config or default_config()
    history = AppendOnlyHistory(config.history_path)
    instructions = _read_instructions(config.participant_instructions_path)

    registry: dict[str, Participant] = {}

    if participants is not None:
        registry = dict(participants)
    elif runtime is not None:
        # 单运行时兼容模式（单元测试用）
        registry["codex"] = Participant(
            id="codex",
            name="Codex",
            model_name="Codex Runtime",
            runtime=runtime,
        )
    else:
        # 默认多参与者共在注册
        if config.gemini_api_key:
            gemini_store = GeminiStateStore(config.thread_state_dir / "gemini_thread.json")
            gemini_adapter = GeminiRuntimeAdapter(
                api_key=config.gemini_api_key,
                state_store=gemini_store,
                model=config.gemini_model,
                developer_instructions=instructions,
            )
            registry["gemini"] = Participant(
                id="gemini",
                name="Gemini",
                model_name=f"Gemini {config.gemini_model.replace('gemini-', '')}",
                runtime=gemini_adapter,
                description="Google AI API (主通道)",
            )

            gemini_b_store = GeminiStateStore(config.thread_state_dir / "gemini_b_thread.json")
            gemini_b_adapter = GeminiRuntimeAdapter(
                api_key=config.gemini_api_key,
                state_store=gemini_b_store,
                model="gemini-2.5-flash",
                developer_instructions=instructions,
            )
            registry["gemini-b"] = Participant(
                id="gemini-b",
                name="Gemini-B",
                model_name="Gemini 2.5 Flash",
                runtime=gemini_b_adapter,
                description="Google AI API (独立通道 B)",
            )

        codex_store = JsonThreadStore(config.thread_state_dir / "codex_thread.json")
        codex_adapter = CodexRuntimeAdapter(
            cwd=config.codex_cwd,
            state_store=codex_store,
            developer_instructions=instructions,
        )
        registry["codex"] = Participant(
            id="codex",
            name="Codex",
            model_name="Codex App-Server",
            runtime=codex_adapter,
            description="本地 ChatGPT OAuth 接入",
        )

        # DeepSeek (opencode-go / zen go 套餐)：有凭据才注册，无凭据则跳过
        deepseek_config = load_deepseek_config()
        if deepseek_config is not None:
            deepseek_store = DeepSeekStateStore(
                config.thread_state_dir / "deepseek_thread.json"
            )
            deepseek_adapter = DeepSeekRuntimeAdapter(
                api_key=deepseek_config["api_key"],
                base_url=deepseek_config["base_url"],
                model=deepseek_config["model"],
                model_name=deepseek_config["model_name"],
                state_store=deepseek_store,
                developer_instructions=instructions,
            )
            registry["deepseek"] = Participant(
                id="deepseek",
                name="DeepSeek",
                model_name=deepseek_config["model_name"],
                runtime=deepseek_adapter,
                description="opencode-go / DeepSeek V4 (zen go 套餐)",
            )

    default_target = "gemini" if "gemini" in registry else next(iter(registry.keys()), "codex")
    service = ConversationService(history, registry, default_target_id=default_target)

    app = FastAPI(title="Cognos 第一发生场", docs_url=None, redoc_url=None)
    app.state.config = config
    app.state.history = history
    app.state.service = service
    app.state.participants = registry
    org_store = OrgStore(config.org_db_path or config.thread_state_dir / "org.db")
    app.state.org_store = org_store
    app.include_router(
        create_org_router(store=org_store, service=service, history=history)
    )
    app.mount("/assets", StaticFiles(directory=FRONTEND_DIR), name="assets")

    @app.get("/", include_in_schema=False)
    async def index() -> FileResponse:
        return FileResponse(FRONTEND_DIR / "index.html")

    @app.get("/api/bootstrap")
    async def bootstrap() -> dict[str, Any]:
        try:
            messages = history.read_all()
        except (HistoryCorruptionError, RuntimeError) as exc:
            raise HTTPException(status_code=500, detail=str(exc)) from exc

        participant_list = [p.to_dict() for p in registry.values()]
        latest_runtime = next(
            (item.get("runtime_ref") for item in reversed(messages) if item.get("runtime_ref")),
            None,
        )
        return {
            "messages": messages,
            "participants": participant_list,
            "default_target": default_target,
            "state": {
                "latest_runtime_ref": latest_runtime,
                "history_count": len(messages),
                "persistence": "Obsidian Vault / 追加式 JSONL",
            },
        }

    @app.post("/api/messages")
    async def send_message(request: SendMessageRequest) -> dict[str, Any]:
        try:
            result = await service.send(
                request.content,
                target=request.target,
                quoted_occurrence_id=request.quoted_occurrence_id,
            )
        except ValueError as exc:
            raise HTTPException(status_code=422, detail=str(exc)) from exc
        except RuntimeUnavailableError as exc:
            raise HTTPException(
                status_code=502,
                detail=f"消息已保存，但目标参与者本次未能返回。错误：{exc}",
            ) from exc
        except RuntimeError as exc:
            raise HTTPException(status_code=500, detail=str(exc)) from exc

        return {
            **result,
            "state": {
                "thread_id": result["reply"]["runtime_ref"]["thread_id"],
                "turn_id": result["reply"]["runtime_ref"]["turn_id"],
            },
        }

    @app.get("/api/health")
    async def health() -> dict[str, str]:
        return {"status": "ready"}

    @app.get("/api/instructions")
    async def get_instructions() -> dict[str, Any]:
        path = config.participant_instructions_path
        try:
            content = path.read_text(encoding="utf-8")
        except OSError as exc:
            raise HTTPException(status_code=500, detail="无法读取参与说明文件") from exc
        
        return {
            "content": content,
            "path": str(path),
            "length": len(content)
        }

    @app.post("/api/handoff/create")
    async def create_handoff(request: HandoffCreateRequest) -> dict[str, Any]:
        try:
            # 兼容旧客户端：旧字段 source_refs 原本承载 occurrence ID。
            effective_occurrence_refs = request.occurrence_refs or request.source_refs
            occurrence_records = []
            seen: set[str] = set()
            for occurrence_id in effective_occurrence_refs:
                if occurrence_id in seen:
                    continue
                seen.add(occurrence_id)
                occurrence = history.get_by_id(occurrence_id)
                if occurrence is not None:
                    occurrence_records.append(occurrence)

            working_directory = str(
                Path(request.working_directory or str(config.codex_cwd)).expanduser().resolve()
            )

            record = history.append_message(
                author="小林",
                content=request.instruction,
                target=request.target_agent,
                handoff_request={
                    "target_agent": request.target_agent,
                    "working_directory": working_directory,
                    "occurrence_refs": effective_occurrence_refs,
                    "source_refs": request.source_refs,
                    "executor_hint": "dsh-headless",
                    "executor_kind": "dsh-headless",
                },
            )

            # E1：追加发生后再落 envelope；envelope 是 projection/cache，不是真相源。
            handoff_dir = config.handoff_dir or (config.thread_state_dir / "handoffs")
            envelope_path = handoff_dir / f"{record['id']}.json"
            envelope = build_envelope(
                handoff_id=record["id"],
                created_at=record["occurred_at"],
                working_directory=working_directory,
                instruction=request.instruction,
                occurrence_refs=effective_occurrence_refs,
                source_refs=request.source_refs,
                occurrence_records=occurrence_records,
                envelope_path=str(envelope_path),
            )
            write_envelope(envelope, envelope_path)
            return {
                "status": "ok",
                "record": record,
                "envelope_path": str(envelope_path),
                "materialized_occurrence_ids": [item["id"] for item in occurrence_records],
            }
        except (RuntimeError, OSError, ValueError) as exc:
            raise HTTPException(status_code=500, detail=str(exc)) from exc

    @app.post("/api/handoff/result")
    async def receive_handoff_result(request: HandoffResultRequest) -> dict[str, Any]:
        try:
            handoff_record = history.get_by_id(request.handoff_id)
            if handoff_record is None:
                raise HTTPException(
                    status_code=422,
                    detail=f"handoff_id 不在当前第一发生场中：{request.handoff_id}",
                )

            record = history.append_message(
                author=request.agent_name,
                content=request.agent_response,
                in_reply_to=request.handoff_id,
                handoff_result={
                    "handoff_id": request.handoff_id,
                    "related_occurrence_ids": request.related_occurrence_ids,
                    "session_ref": request.session_ref,
                    "git_provenance": request.git_provenance,
                    "test_provenance": request.test_provenance,
                    "changed_files": request.changed_files,
                    "commands_run": request.commands_run,
                    "errors": request.errors,
                },
            )
            return {"status": "ok", "record": record}
        except HTTPException:
            raise
        except RuntimeError as exc:
            raise HTTPException(status_code=500, detail=str(exc)) from exc

    return app


app = create_app()

