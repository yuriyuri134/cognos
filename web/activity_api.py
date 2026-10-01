"""活动入口。软件操作与语义地位分开，修改基于明确修订。"""

from __future__ import annotations

import json
from dataclasses import asdict
from typing import Any

from fastapi import APIRouter, HTTPException
from pydantic import BaseModel, Field

from activity_program import ProgramError, compile_activity, document_version
from activity_service import ActivityService, validate_activity_refs
from activity_transfer import discover_experience_candidates
from activity_store import ActivityConflict, ActivityStore
from activity_acquisition import compile_acquisition
from cognitive_runtime import activity_view_from_runs, formation_view


class ActivityCreate(BaseModel):
    title: str = Field(default="", max_length=200)


class ActivityFromOccurrence(BaseModel):
    occurrence_id: str = Field(min_length=1, max_length=200)
    title: str = Field(default="从已有发生开始", max_length=200)


class ActivityPublish(BaseModel):
    request_id: str = Field(min_length=1, max_length=100)
    kind: str = Field(pattern="^(message|decision|work_item)$")
    title: str = Field(default="", max_length=200)
    content: str = Field(min_length=1, max_length=100_000)
    source_document_id: str | None = None
    source_document_version: int | None = Field(default=None, ge=1)


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


class FormationUpdate(BaseModel):
    revision: int = Field(ge=1)
    request_id: str = Field(min_length=1, max_length=100)
    formation_id: str = Field(min_length=1, max_length=200)
    status: str = Field(pattern="^(active|dormant|changed)$")
    text: str | None = Field(default=None, max_length=50_000)
    refs: list[dict[str, Any]] | None = Field(default=None, max_length=100)


class ActivityMaterialImport(BaseModel):
    revision: int = Field(ge=1)
    request_id: str = Field(min_length=1, max_length=100)
    source_activity: str
    source_revision: int = Field(ge=1)
    document: str
    version: int | None = Field(default=None, ge=1)
    kind: str = Field(default="material", pattern="^(material|construction)$")
    note: str = Field(default="", max_length=4000)


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

    @router.post("/api/projects/{project_id}/activities/from-occurrence")
    def create_from_occurrence(project_id: str, request: ActivityFromOccurrence):
        if org_store.get_project(project_id) is None:
            raise HTTPException(404, "项目不存在")
        raw = history.get_by_id(request.occurrence_id)
        if raw is None:
            raise HTTPException(404, "被引用的发生不存在")
        raw_project = raw.get("project_id")
        if raw_project not in (None, project_id):
            raise HTTPException(422, "只能从当前项目的发生开始活动")
        activity = store.create(project_id, request.title.strip() or "从已有发生开始")
        try:
            updated = store.change(activity["id"], activity["revision"], [{
                "action": "add_document", "title": request.title.strip() or "引用已有发生",
                "format": "text", "body": raw.get("content", ""), "immutable": True,
            }], actor="小林", reason="从项目已有发生开始活动", allow_sources=True,
                occurrence_id=raw["id"], source_author=raw.get("author", ""))
        except (ActivityConflict, ValueError, TypeError) as exc:
            raise HTTPException(422, str(exc)) from exc
        return {"activity": updated, "source_occurrence_id": raw["id"]}

    @router.get("/api/activities/{ident}")
    def detail(ident: str, revision: int | None = None):
        activity = require(ident, revision)
        messages = [item for item in history.read_all() if item.get("activity_id") == ident]
        runs = store.runs(ident)
        current_view = activity_view_from_runs(runs, activity["state"]) if revision is None else None
        return {"activity": activity, "history": store.history(ident),
                "runs": [service.view_run(item) for item in runs],
                # Event updates do not all increment the activity revision. Do
                # not label today's event projection as a past revision's view.
                "understanding": ({"scope": "current_activity",
                                   "active": current_view["formations"]["active"],
                                   "dormant": current_view["formations"]["dormant"],
                                   "history": current_view["formation_history"]}
                                  if current_view is not None else None),
                "activity_view": current_view,
                "moves": store.list_moves(ident), "messages": messages}

    @router.get("/api/activities/{ident}/preview")
    def preview(ident: str, revision: int | None = None):
        activity = require(ident, revision)
        try:
            return {"status": "ok", "revision": activity["revision"], **asdict(compile_activity(activity["state"]))}
        except ProgramError as exc:
            return {"status": "failed", "revision": activity["revision"], "error": str(exc)}

    @router.get("/api/activities/{ident}/contexts")
    def context_snapshots(ident: str, run_id: str | None = None, limit: int = 100):
        require(ident)
        try:
            snapshots = store.list_context_snapshots(ident, run_id=run_id, limit=limit)
        except ValueError as exc:
            raise HTTPException(422, str(exc)) from exc
        return {"snapshots": [{k: v for k, v in item.items() if k != "rendered_prompt"}
                              for item in snapshots]}

    @router.get("/api/activities/{ident}/contexts/{snapshot_id}")
    def context_snapshot(ident: str, snapshot_id: str):
        require(ident)
        snapshot = store.get_context_snapshot(snapshot_id)
        if snapshot is None or snapshot["activity_id"] != ident:
            raise HTTPException(404, "上下文快照不存在")
        requests = []
        for run in store.runs(ident):
            if run["id"] != snapshot["run_id"]:
                continue
            for event in run.get("events", []):
                if event.get("context_snapshot_id") == snapshot_id:
                    requests = event.get("runtime_requests", [])
                    break
        return {"snapshot": snapshot, "runtime_requests": requests}

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

    @router.get("/api/activities/{ident}/history-browse")
    def browse_history(ident: str, before: str | None = None, limit: int = 20):
        require(ident)
        try:
            return service.browse_history(ident, before=before, limit=limit)
        except ProgramError as exc:
            raise HTTPException(422, str(exc)) from exc

    @router.get("/api/activities/{ident}/experience-candidates")
    def experience_candidates(ident: str, limit: int = 20):
        activity = require(ident)
        try:
            candidates = discover_experience_candidates(store, activity, limit=limit)
        except ProgramError as exc:
            raise HTTPException(422, str(exc)) from exc
        return {"candidates": candidates, "automatic_adoption": False}

    @router.post("/api/activities/{ident}/acquire")
    async def acquire(ident: str, request: ActivityAcquire):
        require(ident)
        try:
            run = await service.acquire_material(ident, **request.model_dump())
        except ActivityConflict as exc:
            raise HTTPException(409, str(exc)) from exc
        return {"run": run, "activity": require(ident)}

    @router.post("/api/activities/{ident}/formations")
    def update_formation(ident: str, request: FormationUpdate):
        activity = require(ident)
        if activity["revision"] != request.revision:
            raise HTTPException(409, "活动已改变，请重新读取")
        current_view = formation_view(store.runs(ident))
        current = next((item for item in current_view["active"] + current_view["dormant"]
                        if item["id"] == request.formation_id), None)
        if current is None:
            raise HTTPException(404, "当前活动中没有这项暂定读法")
        if request.status == "changed" and request.text is None:
            raise HTTPException(422, "修订读法时需要提供新的文字")
        if request.refs is not None:
            try:
                validate_activity_refs(activity["state"], request.refs)
            except (ProgramError, ValueError, TypeError) as exc:
                raise HTTPException(422, str(exc)) from exc
        try:
            run, fresh = store.begin_run(ident, request.request_id, {
                "revision": request.revision, "type": "user_formation_update",
                "formation_id": request.formation_id, "status": request.status,
                "text": request.text, "refs": request.refs,
            })
        except ActivityConflict as exc:
            raise HTTPException(409, str(exc)) from exc
        if fresh:
            update: dict[str, Any] = {"id": request.formation_id, "status": request.status}
            if request.text is not None:
                update["text"] = request.text
            if request.refs is not None:
                update["refs"] = request.refs
            raw = history.append_message(
                author="小林", content="直接修订活动中的暂定读法。",
                project_id=activity["project_id"], activity_id=ident,
                activity_change_request=request.model_dump())
            run["events"].append({
                "context": {"participant": {"id": "user", "name": "小林", "kind": "human"}},
                "occurrence_id": raw["id"], "formation_updates": [update],
                "tension_updates": [], "results": [],
            })
            run["status"] = "updated"
            store.save_run(run)
        current_activity = require(ident)
        activity_view = activity_view_from_runs(store.runs(ident), current_activity["state"])
        return {"run": run,
                # Temporary adapter for older consumers.  New consumers should
                # read the canonical disposable ActivityView below.
                "understanding": {"scope": "current_activity",
                                  "active": activity_view["formations"]["active"],
                                  "dormant": activity_view["formations"]["dormant"],
                                  "history": activity_view["formation_history"]},
                "activity_view": activity_view,
                "activity": current_activity}

    @router.post("/api/activities/{ident}/imports/from-activity")
    async def import_activity(ident: str, request: ActivityMaterialImport):
        require(ident)
        try:
            run = await service.import_material(ident, **request.model_dump())
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
        activity = require(ident)
        raw = history.get_by_id(request.occurrence_id)
        if raw is None:
            raise HTTPException(404, "被引用的发生不存在")
        raw_project = raw.get("project_id")
        if raw_project not in (None, activity["project_id"]):
            raise HTTPException(422, "只能引入当前项目中的发生")
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

    @router.post("/api/activities/{ident}/publish")
    def publish(ident: str, request: ActivityPublish):
        """用户明确确认后，才把 Activity 内容写回 Organization。"""
        activity = require(ident)
        refs = [f"activity:{ident}"]
        if request.source_document_id:
            try:
                source = document_version(activity["state"], request.source_document_id,
                                         request.source_document_version)
            except ProgramError as exc:
                raise HTTPException(422, str(exc)) from exc
            refs.append(f"document:{source['id']}@{source['version']}")
        publish_ref = f"publish:{request.request_id}"
        refs.append(publish_ref)
        project_id = activity["project_id"]
        if request.kind == "message":
            for record in history.read_all():
                published = record.get("activity_publish") or {}
                if published.get("request_id") == request.request_id and record.get("project_id") == project_id:
                    return {"published": {"kind": "message", "object": record,
                                           "request_id": request.request_id}, "activity": activity}
            record = history.append_message(
                author="小林", content=request.content, project_id=project_id,
                activity_id=ident, source_refs=refs,
                activity_publish={"request_id": request.request_id, "kind": request.kind,
                                  "activity_id": ident, "source_document_id": request.source_document_id})
            return {"published": {"kind": request.kind, "object": record,
                                   "request_id": request.request_id}, "activity": activity}
        if request.kind == "decision":
            existing = next((item for item in org_store.list_decisions(project_id)
                             if publish_ref in item.get("source_refs", [])), None)
            decision = existing or org_store.create_decision(
                project_id, request.content, source_refs=refs,
                decided_by="小林", status="proposed")
            return {"published": {"kind": request.kind, "object": decision,
                                   "request_id": request.request_id}, "activity": activity}
        existing = next((item for item in org_store.list_work_items(project_id)
                         if publish_ref in item.get("source_refs", [])), None)
        item = existing or org_store.create_work_item(
            project_id, request.title.strip() or "从活动形成的工作项",
            description=request.content, source_refs=refs, actor="小林")
        return {"published": {"kind": request.kind, "object": item,
                               "request_id": request.request_id}, "activity": activity}

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
