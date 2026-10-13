"""Organization Slice 1：项目频道 / 决定 / 工作项 / 时间线（薄层）。

原则：
- 这些是普通软件对象，不是 Space / Attempt / Subject 本体。
- 真相源仍是 Vault 第一发生场.jsonl（append-only）；本模块只做作用域投影与普通 CRUD。
- 旧 occurrence 没有 conversation_id 时不迁移原文；读取侧把 project_id 投影到项目默认频道。
"""

from __future__ import annotations

from typing import Any, Annotated

from fastapi import APIRouter, HTTPException, Depends, Request
from pydantic import BaseModel, Field

from auth_middleware import require_login, require_project_member as auth_require_project_member

from error_utils import runtime_api_detail


class ConversationCreateRequest(BaseModel):
    title: str = Field(..., min_length=1, max_length=120)
    kind: str = Field(default="channel", pattern="^(channel|dm|group)$")


class DirectConversationCreateRequest(BaseModel):
    account_id: str = Field(..., min_length=1)


class ConversationMessageRequest(BaseModel):
    content: str = Field(..., max_length=100_000)
    target: str | None = None
    quoted_occurrence_id: str | None = None
    author: str | None = Field(default=None, max_length=80, description="Legacy field; authenticated session determines the author")
    search: bool = False
    search_query: str | None = None


class DecisionCreateRequest(BaseModel):
    content: str = Field(..., min_length=1, max_length=100_000)
    source_refs: list[str] = Field(default_factory=list)
    decided_by: str = Field(default="小林", max_length=80)
    status: str = Field(default="accepted", pattern="^(accepted|proposed|rejected|superseded)$")
    reopen_condition: str = Field(default="", max_length=4000)


class WorkItemCreateRequest(BaseModel):
    title: str = Field(..., min_length=1, max_length=200)
    description: str = Field(default="", max_length=50_000)
    assignee_member_id: str | None = None
    source_refs: list[str] = Field(default_factory=list)
    decision_id: str | None = None


class WorkItemUpdateRequest(BaseModel):
    title: str | None = Field(default=None, max_length=200)
    description: str | None = Field(default=None, max_length=50_000)
    status: str | None = Field(default=None, pattern="^(open|in_progress|blocked|done)$")
    assignee_member_id: str | None = None
    decision_id: str | None = None


def _excerpt(text: Any, limit: int = 160) -> str:
    flat = " ".join(str(text or "").split())
    return flat if len(flat) <= limit else flat[:limit] + "…"


def _decode_source_refs(value: Any) -> list[str]:
    if not isinstance(value, list):
        return []
    return [str(item) for item in value if isinstance(item, str) and item.strip()]


def create_project_router(*, store: Any, service: Any, history: Any) -> APIRouter:
    """store: OrgStore；service: ConversationService；history: AppendOnlyHistory。"""

    router = APIRouter()

    def require_project(project_id: str) -> dict[str, Any]:
        project = store.get_project(project_id)
        if project is None:
            raise HTTPException(status_code=404, detail="项目不存在")
        return project

    def require_project_member(project_id: str, account: dict[str, Any]) -> dict[str, Any]:
        # Single shared rule (auth_middleware); kept as a thin alias so this
        # module's call sites read the same way they always have.
        return auth_require_project_member(store, project_id, account)

    # ---------- 私信 ----------

    @router.get("/api/direct-conversations")
    def list_direct_conversations(
        account: Annotated[dict[str, Any], Depends(require_login)],
        request: Request,
    ) -> dict[str, Any]:
        conversations = store.list_direct_conversations(account["id"])
        enriched = []
        for conversation in conversations:
            other_id = store.direct_other_account(conversation, account["id"])
            other = request.app.state.auth_store.get_account(other_id) if other_id else None
            enriched.append({**conversation, "other_account": other})
        return {"conversations": enriched}

    @router.post("/api/direct-conversations")
    def create_direct_conversation(
        request: DirectConversationCreateRequest,
        account: Annotated[dict[str, Any], Depends(require_login)],
        http_request: Request,
    ) -> dict[str, Any]:
        other_id = request.account_id.strip()
        other = http_request.app.state.auth_store.get_account(other_id)
        if other is None:
            raise HTTPException(status_code=404, detail="对方账号不存在")
        try:
            conversation = store.get_or_create_direct_conversation(account["id"], other_id)
        except ValueError as exc:
            raise HTTPException(status_code=422, detail=str(exc)) from exc
        return {"conversation": conversation, "other_account": other}

    @router.get("/api/direct-conversations/{conversation_id}/messages")
    def direct_messages(
        conversation_id: str,
        account: Annotated[dict[str, Any], Depends(require_login)],
    ) -> dict[str, Any]:
        conversation = store.get_direct_conversation(conversation_id)
        if conversation is None or store.direct_other_account(conversation, account["id"]) is None:
            raise HTTPException(status_code=404, detail="私信不存在")
        records = [
            item for item in history.read_all()
            if item.get("conversation_id") == f"dm:{conversation_id}"
        ]
        return {"conversation": conversation, "messages": records}

    @router.post("/api/direct-conversations/{conversation_id}/messages")
    async def send_direct_message(
        conversation_id: str,
        request: ConversationMessageRequest,
        account: Annotated[dict[str, Any], Depends(require_login)],
    ) -> dict[str, Any]:
        conversation = store.get_direct_conversation(conversation_id)
        if conversation is None or store.direct_other_account(conversation, account["id"]) is None:
            raise HTTPException(status_code=404, detail="私信不存在")
        if not request.content.strip():
            raise HTTPException(status_code=422, detail="消息不能为空")
        user_record = history.append_message(
            author=account["display_name"],
            author_account_id=account["id"],
            content=request.content,
            conversation_id=f"dm:{conversation_id}",
            external_source="cognos_direct_message",
        )
        return {"conversation": conversation, "user": user_record}

    # ---------- 会话与项目频道 ----------

    @router.get("/api/projects/{project_id}/conversations")
    def list_conversations(project_id: str, account: Annotated[dict[str, Any], Depends(require_login)]) -> dict[str, Any]:
        require_project_member(project_id, account)
        channel = store.ensure_project_channel(project_id)
        return {"default_conversation_id": channel["id"], "conversations": store.list_conversations(project_id)}

    @router.post("/api/projects/{project_id}/conversations")
    def create_conversation(
        project_id: str, request: ConversationCreateRequest,
        account: Annotated[dict[str, Any], Depends(require_login)],
    ) -> dict[str, Any]:
        project = require_project_member(project_id, account)
        conversation = store.create_conversation(
            project["org_id"], project_id, request.title.strip(), request.kind
        )
        return {"conversation": conversation}

    @router.get("/api/conversations/{conversation_id}/messages")
    def conversation_messages(conversation_id: str, account: Annotated[dict[str, Any], Depends(require_login)]) -> dict[str, Any]:
        conversation = store.get_conversation(conversation_id)
        if conversation is None:
            raise HTTPException(status_code=404, detail="会话不存在")
        if conversation.get("project_id"):
            require_project_member(conversation["project_id"], account)

        records = history.read_all()
        if conversation.get("is_default"):
            # 投影规则：旧 occurrence 无 conversation_id 时，按 project_id 归入项目默认频道；不回写原文。
            messages = [
                item
                for item in records
                if item.get("conversation_id") == conversation_id
                or (
                    not item.get("conversation_id")
                    and item.get("project_id") == conversation.get("project_id")
                )
            ]
        else:
            messages = [item for item in records if item.get("conversation_id") == conversation_id]

        quoted: dict[str, Any] = {}
        for message in messages:
            quoted_id = message.get("quoted_occurrence_id")
            if quoted_id and quoted_id not in quoted:
                record = history.get_by_id(quoted_id)
                if record is not None:
                    quoted[quoted_id] = record

        project = store.get_project(conversation["project_id"]) if conversation.get("project_id") else None
        return {
            "conversation": conversation,
            "project": project,
            "messages": messages,
            "quoted": quoted,
            "members": store.list_project_members(conversation["project_id"]) if conversation.get("project_id") else [],
        }

    @router.post("/api/conversations/{conversation_id}/messages")
    async def send_conversation_message(
        conversation_id: str, request: ConversationMessageRequest,
        account: Annotated[dict[str, Any], Depends(require_login)],
    ) -> dict[str, Any]:
        conversation = store.get_conversation(conversation_id)
        if conversation is None:
            raise HTTPException(status_code=404, detail="会话不存在")
        if conversation.get("project_id"):
            require_project_member(conversation["project_id"], account)
        try:
            result = await service.send(
                request.content,
                target=request.target,
                quoted_occurrence_id=request.quoted_occurrence_id,
                project_id=conversation.get("project_id"),
                conversation_id=conversation_id,
                search=request.search,
                search_query=request.search_query,
                author=account["display_name"],
                author_account_id=account["id"],
            )
        except ValueError as exc:
            raise HTTPException(status_code=422, detail=str(exc)) from exc
        except Exception as exc:  # RuntimeUnavailableError 等：原文已保存
            raise HTTPException(status_code=502, detail=runtime_api_detail("消息已保存，但", exc)) from exc
        result["conversation_id"] = conversation_id
        return result

    # ---------- 决定 ----------

    @router.get("/api/projects/{project_id}/decisions")
    def list_decisions(project_id: str, account: Annotated[dict[str, Any], Depends(require_login)]) -> dict[str, Any]:
        require_project_member(project_id, account)
        return {"decisions": store.list_decisions(project_id)}

    @router.post("/api/projects/{project_id}/decisions")
    def create_decision(
        project_id: str, request: DecisionCreateRequest,
        account: Annotated[dict[str, Any], Depends(require_login)],
    ) -> dict[str, Any]:
        require_project_member(project_id, account)
        decision = store.create_decision(
            project_id,
            request.content.strip(),
            source_refs=_decode_source_refs(request.source_refs),
            decided_by=account["display_name"],
            status=request.status,
            reopen_condition=request.reopen_condition,
        )
        return {"decision": decision}

    # ---------- 工作项 ----------

    @router.get("/api/projects/{project_id}/work-items")
    def list_work_items(project_id: str, account: Annotated[dict[str, Any], Depends(require_login)]) -> dict[str, Any]:
        require_project_member(project_id, account)
        return {"work_items": store.list_work_items(project_id)}

    @router.post("/api/projects/{project_id}/work-items")
    def create_work_item(
        project_id: str, request: WorkItemCreateRequest,
        account: Annotated[dict[str, Any], Depends(require_login)],
    ) -> dict[str, Any]:
        require_project_member(project_id, account)
        item = store.create_work_item(
            project_id,
            request.title.strip(),
            description=request.description,
            assignee_member_id=request.assignee_member_id,
            source_refs=_decode_source_refs(request.source_refs),
            decision_id=request.decision_id,
            actor=account["display_name"],
        )
        return {"work_item": item}

    @router.get("/api/work-items/{work_item_id}")
    def work_item_detail(work_item_id: str, account: Annotated[dict[str, Any], Depends(require_login)]) -> dict[str, Any]:
        item = store.get_work_item(work_item_id)
        if item is None:
            raise HTTPException(status_code=404, detail="工作项不存在")
        require_project_member(item["project_id"], account)
        return {"work_item": item, "events": store.list_work_item_events(work_item_id)}

    @router.patch("/api/work-items/{work_item_id}")
    def update_work_item(
        work_item_id: str, request: WorkItemUpdateRequest,
        account: Annotated[dict[str, Any], Depends(require_login)],
    ) -> dict[str, Any]:
        existing = store.get_work_item(work_item_id)
        if existing is None:
            raise HTTPException(status_code=404, detail="工作项不存在")
        require_project_member(existing["project_id"], account)
        payload = request.model_dump(exclude_unset=True)
        try:
            item = store.update_work_item(
                work_item_id,
                title=payload.get("title"),
                description=payload.get("description"),
                status=payload.get("status"),
                assignee_member_id=payload.get("assignee_member_id"),
                decision_id=payload.get("decision_id"),
                actor=account["display_name"],
            )
        except ValueError as exc:
            raise HTTPException(status_code=422, detail=str(exc)) from exc
        if item is None:
            raise HTTPException(status_code=404, detail="工作项不存在")
        return {"work_item": item}

    # ---------- 项目时间线（只读聚合，不新建真相源） ----------

    @router.get("/api/projects/{project_id}/timeline")
    def project_timeline(project_id: str, account: Annotated[dict[str, Any], Depends(require_login)]) -> dict[str, Any]:
        project = require_project_member(project_id, account)
        events: list[dict[str, Any]] = []

        for record in history.read_all():
            if record.get("project_id") != project_id:
                continue
            if record.get("handoff_result"):
                kind = "result"
            elif record.get("handoff_request"):
                kind = "handoff"
            else:
                kind = "message"
            events.append(
                {
                    "kind": kind,
                    "at": record.get("occurred_at"),
                    "ref": record.get("id"),
                    "author": record.get("author"),
                    "summary": _excerpt(record.get("content")),
                }
            )

        for decision in store.list_decisions(project_id):
            events.append(
                {
                    "kind": "decision",
                    "at": decision.get("decided_at"),
                    "ref": decision.get("id"),
                    "author": decision.get("decided_by"),
                    "summary": _excerpt(decision.get("content")),
                    "status": decision.get("status"),
                }
            )

        for item in store.list_work_items(project_id):
            for event in store.list_work_item_events(item["id"]):
                events.append(
                    {
                        "kind": "work_item",
                        "at": event.get("created_at"),
                        "ref": item["id"],
                        "author": event.get("actor"),
                        "summary": f"{item['title']} · {event.get('kind')}：{_excerpt(event.get('detail'), 100)}",
                        "event_kind": event.get("kind"),
                        "status": item.get("status"),
                    }
                )

        events.sort(key=lambda item: item.get("at") or "", reverse=True)
        return {"project": project, "events": events[:300]}

    return router
