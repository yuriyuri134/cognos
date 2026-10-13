"""探究 API：question + 多次可再进入的尝试。

探究是应用层对象，不是新本体。每次尝试 = 一次 AI 调用 + 工作报告；
尝试之间通过 previous working reports 相接；raw occurrence 仍只进第一发生场。
"""

from __future__ import annotations

import json
from typing import Any, Annotated

from fastapi import APIRouter, HTTPException, Depends
from pydantic import BaseModel, Field

from auth_middleware import require_login, require_project_member

from error_utils import runtime_api_detail

from inquiry_store import InquiryStore


class InquiryCreateRequest(BaseModel):
    question: str = Field(..., min_length=1, max_length=5000)
    author: str | None = Field(default=None, max_length=80, description="Legacy field; authenticated session determines the author")


class AttemptRequest(BaseModel):
    content: str = ""
    target: str | None = None
    quoted_occurrence_id: str | None = None
    author: str | None = Field(default=None, max_length=80, description="Legacy field; authenticated session determines the author")


def create_inquiry_router(
    *,
    store: InquiryStore,
    service: Any,
    history: Any,
    org_store: Any,
) -> APIRouter:
    """service: ConversationService（duck-typed）；history: AppendOnlyHistory。"""

    router = APIRouter()

    @router.post("/api/projects/{project_id}/inquiries")
    async def create_inquiry(
        project_id: str, request: InquiryCreateRequest,
        account: Annotated[dict[str, Any], Depends(require_login)],
    ) -> dict[str, Any]:
        require_project_member(org_store, project_id, account)
        inquiry = store.create_inquiry(project_id, request.question.strip())
        user_record = history.append_message(
            author=account["display_name"],
            author_account_id=account["id"],
            content=request.question.strip(),
            project_id=project_id,
            inquiry_id=inquiry["id"],
            inquiry_question=True,
        )
        return {"inquiry": inquiry, "user_record": user_record}

    @router.get("/api/projects/{project_id}/inquiries")
    def list_inquiries(project_id: str,
                       account: Annotated[dict[str, Any], Depends(require_login)]) -> dict[str, Any]:
        require_project_member(org_store, project_id, account)
        return {"inquiries": store.list_inquiries(project_id)}

    @router.get("/api/inquiries/{inquiry_id}")
    def get_inquiry(inquiry_id: str,
                    account: Annotated[dict[str, Any], Depends(require_login)]) -> dict[str, Any]:
        inquiry = store.get_inquiry(inquiry_id)
        if inquiry is None:
            raise HTTPException(status_code=404, detail="探究不存在")
        require_project_member(org_store, inquiry["project_id"], account)
        attempts = store.list_attempts(inquiry_id)
        messages = [m for m in history.read_all() if m.get("inquiry_id") == inquiry_id]
        return {"inquiry": inquiry, "attempts": attempts, "messages": messages}

    @router.post("/api/inquiries/{inquiry_id}/attempts")
    async def create_attempt(
        inquiry_id: str, request: AttemptRequest,
        account: Annotated[dict[str, Any], Depends(require_login)],
    ) -> dict[str, Any]:
        inquiry = store.get_inquiry(inquiry_id)
        if inquiry is None:
            raise HTTPException(status_code=404, detail="探究不存在")
        previous_attempts = store.list_attempts(inquiry_id)
        require_project_member(org_store, inquiry["project_id"], account)
        attempt_no = len(previous_attempts) + 1
        try:
            result = await service.send_attempt(
                content=request.content,
                target=request.target,
                quoted_occurrence_id=request.quoted_occurrence_id,
                project_id=inquiry["project_id"],
                inquiry=inquiry,
                previous_attempts=previous_attempts,
                attempt_no=attempt_no,
                author=account["display_name"],
                author_account_id=account["id"],
            )
        except ValueError as exc:
            raise HTTPException(status_code=422, detail=str(exc)) from exc
        except Exception as exc:  # RuntimeUnavailableError 等：发生已保存
            raise HTTPException(status_code=502, detail=runtime_api_detail("尝试未完成，但", exc)) from exc

        attempt = store.add_attempt(
            inquiry_id,
            attempt_no,
            result["target"]["id"],
            result["reply"]["id"],
            json.dumps(result["working_report"] or {}, ensure_ascii=False),
        )
        return {**result, "attempt": attempt}

    return router
