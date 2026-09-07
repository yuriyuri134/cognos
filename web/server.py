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
from deepseek_runtime import DeepSeekRuntimeAdapter, DeepSeekStateStore, load_deepseek_config, list_opencode_go_models
from history import AppendOnlyHistory, HistoryCorruptionError
from handoff.envelope import build_envelope, write_envelope
from org_api import create_org_router
from org_store import OrgStore
from inquiry_api import create_inquiry_router
from inquiry_store import InquiryStore
from search_provider import SearchUnavailableError, format_search_material, web_search
from situation import (
    build_attempt_prompt,
    build_situation,
    build_situation_prompt,
    format_questions,
    load_frame_sources,
    load_situation,
    parse_orientation,
    save_situation,
)


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
    situation_dir: Path | None = None


class SendMessageRequest(BaseModel):
    content: str
    target: str | None = None
    quoted_occurrence_id: str | None = None
    search: bool = False
    search_query: str | None = None


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
    """串行组织一次发言与特定参与者的 Runtime turn，支持同一发生场内多参与者共在。

    每次调用前由系统机械构造 Situation Projection（不是模型自由总结），
    回答后保留 situation provenance 并解析 orientation gate。
    """

    def __init__(
        self,
        history: AppendOnlyHistory,
        participants: dict[str, Participant],
        default_target_id: str | None = None,
        org_store: OrgStore | None = None,
        situation_dir: Path | None = None,
        frame_sources: list[dict[str, Any]] | None = None,
        search_fn: Any | None = None,
    ) -> None:
        self.history = history
        self.participants = participants
        self.default_target_id = default_target_id or (next(iter(participants.keys())) if participants else "gemini")
        self.org_store = org_store
        self.situation_dir = situation_dir
        self.frame_sources = frame_sources or []
        self.search_fn = search_fn or web_search
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
        search: bool = False,
        search_query: str | None = None,
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

        situation = self._make_situation(
            participant, target_content or content, quoted_record, project_id
        )
        actual_input = build_situation_prompt(situation)

        # ---------- 可选联网检索：抓取带来源的材料注入上下文（不自动视为事实） ----------
        search_sources: list[dict[str, str]] | None = None
        search_error: str | None = None
        effective_query: str | None = None
        if search:
            effective_query = (
                search_query.strip()
                if search_query and search_query.strip()
                else (target_content or content).strip()
            )
            try:
                search_sources = await self.search_fn(effective_query)
                if search_sources:
                    material = format_search_material(search_sources)
                    actual_input = f"{material}\n\n{actual_input}"
            except Exception as exc:  # 检索失败不阻断沟通，留痕并照常发送
                search_error = f"{type(exc).__name__}: {exc}"

        async with self._send_lock:
            user_record = self.history.append_message(
                author="小林",
                content=content,
                target=participant.name,
                target_participant_id=participant.id,
                quoted_occurrence_id=quoted_occurrence_id if quoted_record else None,
                project_id=project_id,
                search_query=effective_query,
                search_error=search_error,
            )
            # 发送给指定参与者的独立运行时，保持该参与者自己的连续性
            reply = await participant.runtime.send(actual_input)

            # orientation gate：解析信封；无法解析时整段当作回答（保留降级）
            orientation, final_text, _working_report = parse_orientation(reply.text)
            if (
                isinstance(orientation, dict)
                and orientation.get("status") == "clarification_required"
                and not final_text
            ):
                final_text = format_questions(orientation)
            if not final_text:
                final_text = reply.text

            actual_model = getattr(reply, "model_used", None) or None
            requested = getattr(reply, "requested_model", None) or getattr(
                participant.runtime, "model", None
            )
            fallback_happened = bool(actual_model and requested and actual_model != requested)

            runtime_ref: dict[str, Any] = {
                "runtime": participant.id,
                "thread_id": reply.thread_id,
                "turn_id": reply.turn_id,
            }
            if actual_model:
                runtime_ref["model"] = actual_model

            ai_record = self.history.append_message(
                author=participant.name,
                author_participant_id=participant.id,
                content=final_text,
                in_reply_to=user_record["id"],
                quoted_occurrence_id=quoted_occurrence_id if quoted_record else None,
                project_id=project_id,
                runtime_ref=runtime_ref,
                situation_ref=situation["situation_id"],
                situation_hash=situation["situation_hash"],
                requested_model=requested,
                actual_model=actual_model,
                fallback_happened=fallback_happened or None,
                orientation=orientation if isinstance(orientation, dict) else None,
                sources=reply.sources,
                search_sources=search_sources,
            )
            return {
                "user": user_record,
                "reply": ai_record,
                "target": participant.to_dict(),
                "situation": {
                    "situation_id": situation["situation_id"],
                    "situation_hash": situation["situation_hash"],
                    "requested_model": requested,
                    "actual_model": actual_model,
                    "fallback_happened": fallback_happened,
                    "orientation": orientation if isinstance(orientation, dict) else None,
                },
                "search": {
                    "query": effective_query,
                    "requested": bool(search),
                    "sources": search_sources,
                    "error": search_error,
                }
                if search
                else None,
            }
    def _make_situation(
        self,
        participant: Participant,
        content: str,
        quoted_record: dict[str, Any] | None,
        project_id: str | None,
        inquiry: dict[str, Any] | None = None,
        previous_attempts: list[dict[str, Any]] | None = None,
    ) -> dict[str, Any]:
        """机械构造处境投影（不冻结成 Space schema），并落盘为投影文件。"""

        requested_model = getattr(participant.runtime, "model", None) or None
        locus: dict[str, Any] = {"type": "field", "organization": None, "project": None}
        project_members: list[dict[str, Any]] = []
        if project_id and self.org_store is not None:
            project = self.org_store.get_project(project_id)
            if project is not None:
                org = self.org_store.get_organization(project["org_id"])
                project_members = self.org_store.list_project_members(project_id)
                locus = {
                    "type": "project",
                    "organization": {
                        "id": project["org_id"],
                        "name": (org or {}).get("name", ""),
                    },
                    "project": {
                        "id": project["id"],
                        "title": project["title"],
                        "description": project["description"],
                        "status": project["status"],
                    },
                }

        situation = build_situation(
            participant={
                "id": participant.id,
                "name": participant.name,
                "model_name": participant.model_name,
                "runtime": participant.id,
            },
            requested_model=requested_model,
            locus=locus,
            content=content,
            quoted=[quoted_record] if quoted_record else [],
            project_members=project_members,
            frame_sources=self.frame_sources,
            reachable={
                "explicit_quotation_ids": [quoted_record["id"] if quoted_record else None],
            },
            inquiry=inquiry,
            previous_attempts=previous_attempts,
        )
        if self.situation_dir is not None:
            try:
                save_situation(self.situation_dir, situation)
            except OSError:
                pass
        return situation

    async def send_attempt(
        self,
        *,
        content: str,
        target: str | None,
        quoted_occurrence_id: str | None,
        project_id: str,
        inquiry: dict[str, Any],
        previous_attempts: list[dict[str, Any]],
        attempt_no: int,
    ) -> dict[str, Any]:
        """一次探究尝试：同一问题上的第 attempt_no 次展开，可被下一次承接。

        与 send() 的区别：输入里带探究问题 + 之前尝试的工作报告；信封要求
        working_report，作为下一次尝试的接点。"""

        target_id, target_content = self.resolve_target(content or "", target)
        if target_id not in self.participants:
            raise ValueError(f"未找到指定的参与者：{target_id}")
        participant = self.participants[target_id]

        quoted_record = None
        if quoted_occurrence_id:
            quoted_record = self.history.get_by_id(quoted_occurrence_id)

        situation = self._make_situation(
            participant,
            target_content or content,
            quoted_record,
            project_id,
            inquiry=inquiry,
            previous_attempts=previous_attempts,
        )
        actual_input = build_attempt_prompt(situation)

        async with self._send_lock:
            user_record = None
            if content.strip():
                user_record = self.history.append_message(
                    author="小林",
                    content=content,
                    target=participant.name,
                    target_participant_id=participant.id,
                    quoted_occurrence_id=quoted_occurrence_id if quoted_record else None,
                    project_id=project_id,
                    inquiry_id=inquiry["id"],
                    attempt_no=attempt_no,
                )

            reply = await participant.runtime.send(actual_input)

            orientation, final_text, working_report = parse_orientation(reply.text)
            if (
                isinstance(orientation, dict)
                and orientation.get("status") == "clarification_required"
                and not final_text
            ):
                final_text = format_questions(orientation)
            if not final_text:
                final_text = reply.text

            actual_model = getattr(reply, "model_used", None) or None
            requested = getattr(reply, "requested_model", None) or getattr(
                participant.runtime, "model", None
            )
            fallback_happened = bool(actual_model and requested and actual_model != requested)

            runtime_ref: dict[str, Any] = {
                "runtime": participant.id,
                "thread_id": reply.thread_id,
                "turn_id": reply.turn_id,
            }
            if actual_model:
                runtime_ref["model"] = actual_model

            ai_record = self.history.append_message(
                author=participant.name,
                author_participant_id=participant.id,
                content=final_text,
                in_reply_to=user_record["id"] if user_record else None,
                quoted_occurrence_id=quoted_occurrence_id if quoted_record else None,
                project_id=project_id,
                inquiry_id=inquiry["id"],
                attempt_no=attempt_no,
                runtime_ref=runtime_ref,
                situation_ref=situation["situation_id"],
                situation_hash=situation["situation_hash"],
                requested_model=requested,
                actual_model=actual_model,
                fallback_happened=fallback_happened or None,
                orientation=orientation if isinstance(orientation, dict) else None,
                working_report=working_report,
            )
            return {
                "user": user_record,
                "reply": ai_record,
                "target": participant.to_dict(),
                "working_report": working_report,
                "situation": {
                    "situation_id": situation["situation_id"],
                    "situation_hash": situation["situation_hash"],
                    "requested_model": requested,
                    "actual_model": actual_model,
                    "fallback_happened": fallback_happened,
                    "orientation": orientation if isinstance(orientation, dict) else None,
                },
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
        situation_dir=Path(
            os.environ.get(
                "COGNOS_SITUATION_DIR",
                str(thread_state_dir / "situations"),
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
    search_fn: Any | None = None,
) -> FastAPI:
    config = config or default_config()
    history = AppendOnlyHistory(config.history_path)
    instructions = _read_instructions(config.participant_instructions_path)

    # 认知框架来源：明确 source refs，由配置机械加载（默认三份 current-view/决策台账）
    vault_root = config.history_path.parents[3]
    extra_frame = os.environ.get("COGNOS_FRAME_SOURCES", "").replace(";", os.pathsep)
    frame_sources = load_frame_sources(
        vault_root,
        [p for p in extra_frame.split(os.pathsep) if p.strip()],
    )

    # OpenCode Go 凭据（默认参与者与额外绑定共用）
    deepseek_config = load_deepseek_config()

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

            # Gemini-Web：联网检索参与者（Search Grounding）；需 GEMINI_WEB=1
            if os.environ.get("GEMINI_WEB", "0") == "1":
                gemini_web_store = GeminiStateStore(
                    config.thread_state_dir / "gemini_web_thread.json"
                )
                gemini_web_adapter = GeminiRuntimeAdapter(
                    api_key=config.gemini_api_key,
                    state_store=gemini_web_store,
                    model=config.gemini_model,
                    developer_instructions=instructions,
                    search=True,
                )
                registry["gemini-web"] = Participant(
                    id="gemini-web",
                    name="Gemini-Web",
                    model_name=f"Gemini {config.gemini_model.replace('gemini-', '')} + 联网检索",
                    runtime=gemini_web_adapter,
                    description="Google AI API · Search Grounding（可实时检索网页）",
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

    # 通用 OpenCode Go runtime binding：Participant 身份与 provider/model 分离。
    # COGNOS_EXTRA_PARTICIPANTS = [{"id":"deepseek-b","name":"DeepSeek-B","model":"deepseek-v4-flash"}, ...]
    # 同一模型可注册多个 Participant，各自 continuity/state 独立。
    if deepseek_config is not None:
        extra_spec = os.environ.get("COGNOS_EXTRA_PARTICIPANTS", "").strip()
        if extra_spec:
            try:
                specs = json.loads(extra_spec)
                for spec in specs:
                    if not isinstance(spec, dict):
                        continue
                    participant_id = (spec.get("id") or "").strip()
                    if not participant_id or participant_id in registry:
                        continue
                    model = (spec.get("model") or deepseek_config["model"]).strip()
                    name = (spec.get("name") or participant_id).strip()
                    state_file = f"{participant_id.replace('-', '_')}_thread.json"
                    store = DeepSeekStateStore(config.thread_state_dir / state_file)
                    adapter = DeepSeekRuntimeAdapter(
                        api_key=deepseek_config["api_key"],
                        base_url=deepseek_config["base_url"],
                        model=model,
                        model_name=name,
                        state_store=store,
                        developer_instructions=instructions,
                    )
                    registry[participant_id] = Participant(
                        id=participant_id,
                        name=name,
                        model_name=name,
                        runtime=adapter,
                        description=f"opencode-go binding / {model}",
                    )
            except (json.JSONDecodeError, TypeError, ValueError):
                pass

    default_target = "gemini" if "gemini" in registry else next(iter(registry.keys()), "codex")
    org_store = OrgStore(config.org_db_path or config.thread_state_dir / "org.db")
    inquiry_store = InquiryStore(
        Path(
            os.environ.get(
                "COGNOS_INQUIRY_DB_PATH",
                str(config.thread_state_dir / "inquiry.db"),
            )
        ).resolve()
    )
    service = ConversationService(
        history,
        registry,
        default_target_id=default_target,
        org_store=org_store,
        situation_dir=config.situation_dir or config.thread_state_dir / "situations",
        frame_sources=frame_sources,
        search_fn=search_fn,
    )

    app = FastAPI(title="Cognos 第一发生场", docs_url=None, redoc_url=None)
    app.state.config = config
    app.state.history = history
    app.state.service = service
    app.state.participants = registry
    app.state.org_store = org_store
    app.state.inquiry_store = inquiry_store
    app.include_router(
        create_org_router(
            store=org_store,
            service=service,
            history=history,
            thread_state_dir=config.thread_state_dir,
        )
    )
    app.include_router(
        create_inquiry_router(
            store=inquiry_store,
            service=service,
            history=history,
            org_store=org_store,
        )
    )
    app.mount("/assets", StaticFiles(directory=FRONTEND_DIR), name="assets")

    @app.get("/api/situations/{situation_id}")
    async def get_situation(situation_id: str) -> dict[str, Any]:
        """开发/检查入口：查看某次回答时 Participant 实际所处的处境投影。"""

        situation_dir = config.situation_dir or config.thread_state_dir / "situations"
        situation = load_situation(situation_dir, situation_id)
        if situation is None:
            raise HTTPException(status_code=404, detail="处境投影不存在")
        return situation

    @app.get("/api/models")
    async def get_models() -> dict[str, Any]:
        """读取现有 OpenCode Go 配置里的模型清单（不硬编码）。"""

        return {
            "opencode_go": list_opencode_go_models(),
            "participants": [
                {
                    "id": p.id,
                    "name": p.name,
                    "requested_model": getattr(p.runtime, "model", None) or p.model_name,
                }
                for p in registry.values()
            ],
        }

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
                search=request.search,
                search_query=request.search_query,
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

