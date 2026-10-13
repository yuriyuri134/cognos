"""Organization v0 API 路由（薄层）。

原则：
- 组织/项目/成员是普通软件层对象，不是 Space/Relation/Subject 本体。
- project_id 只是 occurrence 的可选操作作用域，不代表一个发生永久只属于一个项目；
  跨项目引用（quoted_occurrence_id 指向任何已有发生）必须成立。
- raw occurrence 仍只追加到第一发生场.jsonl；本项目接口只是作用域投影。
"""

from __future__ import annotations

from typing import Any, Annotated

from fastapi import APIRouter, HTTPException, Depends, Request
from pydantic import BaseModel, Field

from auth_middleware import require_login, join_project, require_org_member, require_project_member

from error_utils import runtime_api_detail

from org_store import OrgStore


class OrgCreateRequest(BaseModel):
    name: str = Field(..., min_length=1, max_length=120)
    description: str = ""


class ProjectCreateRequest(BaseModel):
    title: str = Field(..., min_length=1, max_length=200)
    description: str = ""


class MemberCreateRequest(BaseModel):
    kind: str = Field(..., pattern="^(human|ai)$")
    name: str = Field(default="", max_length=80)
    account_id: str | None = None
    participant_id: str | None = None
    org_role: str = ""


class MemberUpdateRequest(BaseModel):
    org_role: str | None = None
    materials: str | None = None


class ProjectMemberAddRequest(BaseModel):
    org_member_id: str = Field(...)


class ProjectPositionRequest(BaseModel):
    position: str = ""


class InvitationCreateRequest(BaseModel):
    invitee_account_id: str = Field(..., min_length=1)


class InvitationResponseRequest(BaseModel):
    accept: bool


class ProjectMessageRequest(BaseModel):
    content: str = Field(..., max_length=100_000)
    target: str | None = None
    quoted_occurrence_id: str | None = None
    author: str | None = Field(default=None, max_length=80, description="Legacy field; authenticated session determines the author")
    search: bool = False
    search_query: str | None = None


def create_org_router(
    *,
    store: OrgStore,
    service: Any,
    history: Any,
    thread_state_dir: Any,
) -> APIRouter:
    """service: ConversationService（duck-typed，避免循环导入）。

    依赖 service.participants / service.default_target_id / service.send(...)。
    """
    router = APIRouter()

    # ---------- 组织 ----------

    @router.get("/api/orgs")
    def list_orgs(account: Annotated[dict[str, Any], Depends(require_login)]) -> dict[str, Any]:
        organizations = [org for org in store.list_organizations()
                         if store.account_member(org["id"], account["id"]) is not None]
        for org in organizations:
            org["projects"] = store.list_projects(org["id"])
        return {"organizations": organizations}

    @router.post("/api/orgs")
    def create_org(
        request: OrgCreateRequest,
        account: Annotated[dict[str, Any], Depends(require_login)],
    ) -> dict[str, Any]:
        org = store.create_organization(request.name.strip(), request.description.strip())
        store.ensure_account_member(org["id"], account)
        return {"organization": org}

    @router.get("/api/orgs/{org_id}")
    def get_org(org_id: str, account: Annotated[dict[str, Any], Depends(require_login)]) -> dict[str, Any]:
        org = store.get_organization(org_id)
        if org is None:
            raise HTTPException(status_code=404, detail="组织不存在")
        if store.account_member(org_id, account["id"]) is None:
            raise HTTPException(status_code=403, detail="你不是该组织成员")
        return {
            "organization": org,
            "projects": store.list_projects(org_id),
            "members": store.list_org_members(org_id),
        }

    @router.post("/api/orgs/{org_id}/projects")
    def create_project(
        org_id: str,
        request: ProjectCreateRequest,
        account: Annotated[dict[str, Any], Depends(require_login)],
    ) -> dict[str, Any]:
        require_org_member(store, org_id, account)
        project = store.create_project(
            org_id, request.title.strip(), request.description.strip()
        )
        # 项目默认频道属于普通软件对象；此处立即建立，避免 UI 与消息写入各自懒创建。
        store.ensure_project_channel(project["id"])
        join_project(store, project["id"], account)
        return {"project": project}

    # ---------- 成员 ----------

    @router.get("/api/invitations")
    def my_invitations(
        account: Annotated[dict[str, Any], Depends(require_login)],
    ) -> dict[str, Any]:
        return {"invitations": store.list_invitations_for_account(account["id"])}

    @router.post("/api/orgs/{org_id}/invitations")
    def invite_member(
        org_id: str,
        request: InvitationCreateRequest,
        account: Annotated[dict[str, Any], Depends(require_login)],
        http_request: Request,
    ) -> dict[str, Any]:
        require_org_member(store, org_id, account)
        invitee = http_request.app.state.auth_store.get_account(request.invitee_account_id)
        if invitee is None:
            raise HTTPException(status_code=404, detail="找不到这个账号")
        try:
            invitation = store.create_invitation(org_id, account["id"], invitee["id"])
        except ValueError as exc:
            raise HTTPException(status_code=422, detail=str(exc)) from exc
        return {"invitation": invitation, "invitee": invitee}

    @router.get("/api/orgs/{org_id}/invitations")
    def org_invitations(
        org_id: str,
        account: Annotated[dict[str, Any], Depends(require_login)],
    ) -> dict[str, Any]:
        try:
            invitations = store.list_org_invitations(org_id, account["id"])
        except ValueError as exc:
            raise HTTPException(status_code=403, detail=str(exc)) from exc
        return {"invitations": invitations}

    @router.post("/api/invitations/{invitation_id}/respond")
    def respond_invitation(
        invitation_id: str,
        request: InvitationResponseRequest,
        account: Annotated[dict[str, Any], Depends(require_login)],
    ) -> dict[str, Any]:
        try:
            invitation = store.respond_invitation(invitation_id, account, request.accept)
        except ValueError as exc:
            raise HTTPException(status_code=403, detail=str(exc)) from exc
        return {"invitation": invitation}

    @router.post("/api/orgs/{org_id}/members")
    def add_member(
        org_id: str,
        request: MemberCreateRequest,
        account: Annotated[dict[str, Any], Depends(require_login)],
        http_request: Request,
    ) -> dict[str, Any]:
        require_org_member(store, org_id, account)
        kind = request.kind
        if request.account_id:
            if kind != "human":
                raise HTTPException(status_code=422, detail="只有人类成员可关联登录账号")
            linked = http_request.app.state.auth_store.get_account(request.account_id)
            if linked is None:
                raise HTTPException(status_code=422, detail="登录账号不存在")
            member = store.ensure_account_member(org_id, linked)
            if request.org_role.strip():
                member = store.update_org_member(member["id"], org_role=request.org_role.strip())
            return {"member": member}
        if not request.name.strip():
            raise HTTPException(status_code=422, detail="成员名称不能为空")
        if kind == "ai":
            if not request.participant_id:
                raise HTTPException(status_code=422, detail="AI 成员必须指定 participant_id")
            if request.participant_id not in service.participants:
                raise HTTPException(
                    status_code=422,
                    detail=f"participant_id 不在当前参与者注册表中：{request.participant_id}",
                )
        member = store.add_org_member(
            org_id,
            kind,
            request.name.strip(),
            request.participant_id if kind == "ai" else None,
            org_role=request.org_role.strip(),
        )
        return {"member": member}

    @router.post("/api/projects/{project_id}/members")
    def add_project_member(
        project_id: str, request: ProjectMemberAddRequest,
        account: Annotated[dict[str, Any], Depends(require_login)],
    ) -> dict[str, Any]:
        require_project_member(store, project_id, account)
        try:
            store.add_project_member(project_id, request.org_member_id)
        except ValueError as exc:
            raise HTTPException(status_code=422, detail=str(exc)) from exc
        return {"status": "ok"}

    # ---------- 项目内沟通 ----------

    @router.patch("/api/orgs/{org_id}/members/{member_id}")
    def update_member(
        org_id: str, member_id: str, request: MemberUpdateRequest,
        account: Annotated[dict[str, Any], Depends(require_login)],
    ) -> dict[str, Any]:
        require_org_member(store, org_id, account)
        current = store.get_member(member_id)
        if current is None or current["org_id"] != org_id:
            raise HTTPException(status_code=404, detail="成员不存在")
        member = store.update_org_member(
            member_id,
            org_role=request.org_role,
            materials=request.materials,
        )
        if member is None:
            raise HTTPException(status_code=404, detail="成员不存在")
        return {"member": member}

    @router.patch("/api/projects/{project_id}/members/{org_member_id}")
    def set_project_position(
        project_id: str, org_member_id: str, request: ProjectPositionRequest,
        account: Annotated[dict[str, Any], Depends(require_login)],
    ) -> dict[str, Any]:
        require_project_member(store, project_id, account)
        if not any(m["id"] == org_member_id for m in store.list_project_members(project_id)):
            raise HTTPException(status_code=404, detail="项目成员不存在")
        store.set_project_member_position(project_id, org_member_id, request.position)
        return {"status": "ok"}

    @router.get("/api/members/{member_id}")
    def member_detail(member_id: str, account: Annotated[dict[str, Any], Depends(require_login)]) -> dict[str, Any]:
        """成员主体空间：身份 + 职位/职责 + 所在项目与负责位置 + 它的发生 + 连续性。"""

        member = store.get_member(member_id)
        if member is None:
            raise HTTPException(status_code=404, detail="成员不存在")
        if store.account_member(member["org_id"], account["id"]) is None:
            raise HTTPException(status_code=403, detail="你不是该组织成员")
        records = history.read_all()
        participant_id = member.get("participant_id")
        account_id = member.get("account_id")
        name = member["name"]

        authored = [
            m
            for m in records
            if (participant_id and m.get("author_participant_id") == participant_id)
            or (account_id and m.get("author_account_id") == account_id)
            or (not participant_id and not account_id and not m.get("author_account_id")
                and m.get("author") == name)
        ]
        addressed = [m for m in records if m.get("target") == name]

        seen: set[str] = set()
        referenced: list[dict[str, Any]] = []
        for m in authored:
            quoted_id = m.get("quoted_occurrence_id")
            if quoted_id and quoted_id not in seen:
                seen.add(quoted_id)
                record = history.get_by_id(quoted_id)
                if record is not None:
                    referenced.append(record)

        thread: dict[str, Any] | None = None
        if participant_id:
            thread_file = thread_state_dir / f"{participant_id.replace('-', '_')}_thread.json"
            if thread_file.exists():
                import json as _json

                thread = {"file": thread_file.name, "exists": True, "size": thread_file.stat().st_size}
                try:
                    data = _json.loads(thread_file.read_text(encoding="utf-8"))
                    if isinstance(data, dict):
                        thread["thread_id"] = str(data.get("thread_id") or "")
                        thread["turns"] = len(data.get("contents") or [])
                except (OSError, ValueError):
                    thread["readable"] = False

        return {
            "member": member,
            "authored": authored[-100:],
            "addressed": addressed[-100:],
            "referenced": referenced[-50:],
            "thread": thread,
        }

    @router.get("/api/projects/{project_id}/messages")
    def project_messages(
        project_id: str,
        account: Annotated[dict[str, Any], Depends(require_login)],
    ) -> dict[str, Any]:
        project = require_project_member(store, project_id, account)
        messages = [
            m for m in history.read_all() if m.get("project_id") == project_id
        ]
        # 被引用但不在本项目流里的发生（跨项目 / 全局引用），供前端展示
        quoted: dict[str, Any] = {}
        for message in messages:
            quoted_id = message.get("quoted_occurrence_id")
            if quoted_id and quoted_id not in quoted:
                record = history.get_by_id(quoted_id)
                if record is not None:
                    quoted[quoted_id] = record
        return {
            "messages": messages,
            "project": project,
            "members": store.list_project_members(project_id),
            "participants": [p.to_dict() for p in service.participants.values()],
            "default_target": service.default_target_id,
            "quoted": quoted,
            "state": {
                "latest_runtime_ref": next(
                    (
                        message.get("runtime_ref")
                        for message in reversed(messages)
                        if message.get("runtime_ref")
                    ),
                    None,
                ),
                "history_count": len(messages),
                "persistence": "Obsidian Vault / 追加式 JSONL（raw 真相源）",
            },
        }

    @router.post("/api/projects/{project_id}/messages")
    async def send_project_message(
        project_id: str, request: ProjectMessageRequest,
        account: Annotated[dict[str, Any], Depends(require_login)],
    ) -> dict[str, Any]:
        require_project_member(store, project_id, account)
        channel = store.ensure_project_channel(project_id)
        try:
            result = await service.send(
                request.content,
                target=request.target,
                quoted_occurrence_id=request.quoted_occurrence_id,
                project_id=project_id,
                conversation_id=channel["id"],
                search=request.search,
                search_query=request.search_query,
                author=account["display_name"],
                author_account_id=account["id"],
            )
        except ValueError as exc:
            raise HTTPException(status_code=422, detail=str(exc)) from exc
        except Exception as exc:  # RuntimeUnavailableError 等：原文已保存
            raise HTTPException(status_code=502, detail=runtime_api_detail("消息已保存，但", exc)) from exc
        result["project_id"] = project_id
        return result

    return router
