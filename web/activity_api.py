"""活动入口。软件操作与语义地位分开，修改基于明确修订。"""

from __future__ import annotations

import json
from dataclasses import asdict
from typing import Any

from fastapi import APIRouter, HTTPException
from pydantic import BaseModel, Field

from activity_program import ProgramError, compile_activity, document_version
from activity_service import ActivityService
from activity_store import ActivityConflict, ActivityStore
from activity_acquisition import compile_acquisition


class ActivityCreate(BaseModel):
    title: str = Field(default="", max_length=200)


class ActivityChange(BaseModel):
    revision: int = Field(ge=1)
    operations: list[dict[str, Any]] = Field(min_length=1, max_length=40)
    reason: str = Field(default="", max_length=4000)


class ActivityContinue(BaseModel):
    revision: int = Field(ge=1)
    request_id: str = Field(min_length=1, max_length=100)
    content: str = Field(default="", max_length=100_000)
    target: str | None = None


class OccurrenceImport(BaseModel):
    revision: int = Field(ge=1)
    occurrence_id: str
    title: str = Field(default="引用已有发生", max_length=200)


class ActivityAcquire(BaseModel):
    revision: int = Field(ge=1)
    request_id: str = Field(min_length=1, max_length=100)


class ActivityAnswer(BaseModel):
    revision: int = Field(ge=1)
    request_id: str = Field(min_length=1, max_length=100)
    answer: str = Field(min_length=1, max_length=100_000)


def create_activity_router(*, store: ActivityStore, service: ActivityService, org_store: Any,
                           history: Any) -> APIRouter:
    router = APIRouter()

    def require(ident: str, revision: int | None = None) -> dict[str, Any]:
        activity = store.get(ident, revision)
        if activity is None:
            raise HTTPException(404, "活动或修订不存在")
        return activity

    @router.get("/api/projects/{project_id}/activities")
    def list_activities(project_id: str):
        if org_store.get_project(project_id) is None:
            raise HTTPException(404, "项目不存在")
        return {"activities": store.list(project_id)}

    @router.post("/api/projects/{project_id}/activities")
    def create(project_id: str, request: ActivityCreate):
        if org_store.get_project(project_id) is None:
            raise HTTPException(404, "项目不存在")
        return {"activity": store.create(project_id, request.title)}

    @router.get("/api/activities/{ident}")
    def detail(ident: str, revision: int | None = None):
        activity = require(ident, revision)
        messages = [item for item in history.read_all() if item.get("activity_id") == ident]
        return {"activity": activity, "history": store.history(ident),
                "runs": [service.view_run(item) for item in store.runs(ident)],
                "moves": store.list_moves(ident), "messages": messages}

    @router.get("/api/activities/{ident}/preview")
    def preview(ident: str, revision: int | None = None):
        activity = require(ident, revision)
        try:
            return {"status": "ok", "revision": activity["revision"], **asdict(compile_activity(activity["state"]))}
        except ProgramError as exc:
            return {"status": "failed", "revision": activity["revision"], "error": str(exc)}

    @router.get("/api/activities/{ident}/documents/{document_id}")
    def read_document(ident: str, document_id: str, version: int | None = None):
        activity = require(ident)
        try:
            return document_version(activity["state"], document_id, version)
        except ProgramError as exc:
            raise HTTPException(404, str(exc)) from exc

    @router.get("/api/activities/{ident}/acquisition")
    def acquisition_preview(ident: str):
        activity = require(ident)
        if not activity["state"].get("source_adopted"):
            return {"status": "inactive", "revision": activity["revision"]}
        try:
            definition, request = compile_acquisition(activity["state"])
            return {"status": "ready", "revision": activity["revision"], "request": request, "reads": definition.reads}
        except ProgramError as exc:
            return {"status": "failed", "revision": activity["revision"], "error": str(exc)}

    @router.post("/api/activities/{ident}/acquire")
    async def acquire(ident: str, request: ActivityAcquire):
        require(ident)
        try:
            run = await service.acquire_material(ident, **request.model_dump())
        except ActivityConflict as exc:
            raise HTTPException(409, str(exc)) from exc
        return {"run": run, "activity": require(ident)}

    @router.post("/api/activities/{ident}/changes")
    def change(ident: str, request: ActivityChange):
        activity = require(ident)
        if activity["revision"] != request.revision:
            raise HTTPException(409, "活动已改变，请重新读取")
        raw = history.append_message(author="小林", content="修改活动内容与组织。",
                                     project_id=activity["project_id"], activity_id=ident,
                                     activity_change_request=request.model_dump())
        try:
            updated = store.change(ident, request.revision, request.operations, actor="小林",
                                   reason=request.reason, allow_sources=True, occurrence_id=raw["id"])
        except ActivityConflict as exc:
            raise HTTPException(409, str(exc)) from exc
        except (ValueError, TypeError) as exc:
            raise HTTPException(422, str(exc)) from exc
        return {"activity": updated, "request_occurrence_id": raw["id"]}

    @router.post("/api/activities/{ident}/imports")
    def import_occurrence(ident: str, request: OccurrenceImport):
        require(ident)
        raw = history.get_by_id(request.occurrence_id)
        if raw is None:
            raise HTTPException(404, "被引用的发生不存在")
        # 只取实际发生正文，不复制外部 DSH 原始 session。
        try:
            updated = store.change(ident, request.revision, [{"action": "add_document", "title": request.title,
                "format": "text", "body": raw["content"], "immutable": True}],
                actor="小林", reason="引用已有发生", allow_sources=True,
                occurrence_id=raw["id"], source_author=raw["author"])
        except ActivityConflict as exc:
            raise HTTPException(409, str(exc)) from exc
        except ValueError as exc:
            raise HTTPException(422, str(exc)) from exc
        return {"activity": updated}

    @router.post("/api/activities/{ident}/moves/{move_id}/answer")
    async def answer_move(ident: str, move_id: str, request: ActivityAnswer):
        require(ident)
        try:
            run = await service.answer_activity(ident, move_id, **request.model_dump())
        except ActivityConflict as exc:
            raise HTTPException(409, str(exc)) from exc
        except KeyError as exc:
            raise HTTPException(404, "活动提议不存在") from exc
        except ValueError as exc:
            raise HTTPException(422, str(exc)) from exc
        return {"run": run, "activity": require(ident)}

    @router.post("/api/activities/{ident}/continue")
    async def continue_activity(ident: str, request: ActivityContinue):
        require(ident)
        try:
            run = await service.continue_activity(ident, **request.model_dump())
        except ActivityConflict as exc:
            raise HTTPException(409, str(exc)) from exc
        except ValueError as exc:
            raise HTTPException(422, str(exc)) from exc
        return {"run": run, "activity": require(ident)}

    return router
