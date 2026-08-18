"""Organization v0 API 路由（薄层）。

原则：
- 组织/项目/成员是普通软件层对象，不是 Space/Relation/Subject 本体。
- project_id 只是 occurrence 的可选操作作用域，不代表一个发生永久只属于一个项目；
  跨项目引用（quoted_occurrence_id 指向任何已有发生）必须成立。
- raw occurrence 仍只追加到第一发生场.jsonl；本项目接口只是作用域投影。
"""

from __future__ import annotations

from typing import Any

from fastapi import APIRouter, HTTPException
from pydantic import BaseModel, Field

from org_store import OrgStore


class OrgCreateRequest(BaseModel):
    name: str = Field(..., min_length=1, max_length=120)
    description: str = ""


class ProjectCreateRequest(BaseModel):
    title: str = Field(..., min_length=1, max_length=200)
    description: str = ""


class MemberCreateRequest(BaseModel):
    kind: str = Field(..., pattern="^(human|ai)$")
    name: str = Field(..., min_length=1, max_length=80)
    participant_id: str | None = None


class ProjectMemberAddRequest(BaseModel):
    org_member_id: str = Field(...)


class ProjectMessageRequest(BaseModel):
    content: str = Field(..., max_length=100_000)
    target: str | None = None
    quoted_occurrence_id: str | None = None


def create_org_router(
    *,
    store: OrgStore,
    service: Any,
    history: Any,
) -> APIRouter:
    """service: ConversationService（duck-typed，避免循环导入）。

    依赖 service.participants / service.default_target_id / service.send(...)。
    """
    router = APIRouter()

    # ---------- 组织 ----------

    @router.get("/api/orgs")
    def list_orgs() -> dict[str, Any]:
        organizations = store.list_organizations()
        for org in organizations:
            org["projects"] = store.list_projects(org["id"])
        return {"organizations": organizations}

    @router.post("/api/orgs")
    def create_org(request: OrgCreateRequest) -> dict[str, Any]:
        org = store.create_organization(request.name.strip(), request.description.strip())
        return {"organization": org}

    @router.get("/api/orgs/{org_id}")
    def get_org(org_id: str) -> dict[str, Any]:
        org = store.get_organization(org_id)
        if org is None:
            raise HTTPException(status_code=404, detail="组织不存在")
        return {
            "organization": org,
            "projects": store.list_projects(org_id),
            "members": store.list_org_members(org_id),
        }

    @router.post("/api/orgs/{org_id}/projects")
    def create_project(org_id: str, request: ProjectCreateRequest) -> dict[str, Any]:
        if store.get_organization(org_id) is None:
            raise HTTPException(status_code=404, detail="组织不存在")
        project = store.create_project(
            org_id, request.title.strip(), request.description.strip()
        )
        return {"project": project}

    # ---------- 成员 ----------

    @router.post("/api/orgs/{org_id}/members")
    def add_member(org_id: str, request: MemberCreateRequest) -> dict[str, Any]:
        if store.get_organization(org_id) is None:
            raise HTTPException(status_code=404, detail="组织不存在")
        kind = request.kind
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
        )
        return {"member": member}

    @router.post("/api/projects/{project_id}/members")
    def add_project_member(
        project_id: str, request: ProjectMemberAddRequest
    ) -> dict[str, Any]:
        if store.get_project(project_id) is None:
            raise HTTPException(status_code=404, detail="项目不存在")
        try:
            store.add_project_member(project_id, request.org_member_id)
        except ValueError as exc:
            raise HTTPException(status_code=422, detail=str(exc)) from exc
        return {"status": "ok"}

    # ---------- 项目内沟通 ----------

    @router.get("/api/projects/{project_id}/messages")
    def project_messages(project_id: str) -> dict[str, Any]:
        project = store.get_project(project_id)
        if project is None:
            raise HTTPException(status_code=404, detail="项目不存在")
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
        project_id: str, request: ProjectMessageRequest
    ) -> dict[str, Any]:
        if store.get_project(project_id) is None:
            raise HTTPException(status_code=404, detail="项目不存在")
        try:
            result = await service.send(
                request.content,
                target=request.target,
                quoted_occurrence_id=request.quoted_occurrence_id,
                project_id=project_id,
            )
        except ValueError as exc:
            raise HTTPException(status_code=422, detail=str(exc)) from exc
        except Exception as exc:  # RuntimeUnavailableError 等：原文已保存
            raise HTTPException(
                status_code=502,
                detail=f"消息已保存，但目标参与者本次未能返回。错误：{exc}",
            ) from exc
        result["project_id"] = project_id
        return result

    return router
