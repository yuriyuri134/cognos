"""First note-space HTTP slice: user-owned spaces, notes, and explicit links."""
from __future__ import annotations

from typing import Any, Annotated
from fastapi import APIRouter, Depends, HTTPException
from pydantic import BaseModel, Field

from auth_middleware import require_login
from notes_store import NotesNotFound, NotesStore
from error_utils import runtime_api_detail


class SpaceCreate(BaseModel):
    title: str = Field(min_length=1, max_length=200)
    description: str = Field(default="", max_length=4000)


class NoteCreate(BaseModel):
    title: str = Field(min_length=1, max_length=300)
    content: str = Field(default="", max_length=500_000)
    source: dict[str, Any] = Field(default_factory=dict)


class NoteUpdate(BaseModel):
    title: str = Field(min_length=1, max_length=300)
    content: str = Field(default="", max_length=500_000)


class LinkCreate(BaseModel):
    source_note_id: str = Field(min_length=1)
    target_note_id: str = Field(min_length=1)
    label: str = Field(default="", max_length=300)


class NoteMessageCreate(BaseModel):
    content: str = Field(min_length=1, max_length=100_000)
    target: str | None = None
    note_id: str | None = None


class NoteMessageRetry(BaseModel):
    target: str | None = None


def create_notes_router(store: NotesStore, service: Any) -> APIRouter:
    router = APIRouter()

    @router.get("/api/notes/spaces")
    def list_spaces(account: Annotated[dict[str, Any], Depends(require_login)]):
        return {"spaces": store.list_spaces(account["id"])}

    @router.post("/api/notes/spaces")
    def create_space(request: SpaceCreate, account: Annotated[dict[str, Any], Depends(require_login)]):
        return {"space": store.create_space(request.title.strip(), request.description,
                                              account["display_name"], account["id"])}

    @router.get("/api/notes/spaces/{space_id}")
    def get_space(space_id: str, account: Annotated[dict[str, Any], Depends(require_login)]):
        try:
            return {"space": store.get_space(space_id, account["id"]),
                    "messages": store.list_messages(space_id, account["id"]),
                    "participants": [p.to_dict() for p in service.participants.values()],
                    "default_target": service.default_target_id}
        except NotesNotFound as exc:
            raise HTTPException(status_code=404, detail=str(exc)) from exc

    @router.post("/api/notes/spaces/{space_id}/notes")
    def create_note(space_id: str, request: NoteCreate,
                    account: Annotated[dict[str, Any], Depends(require_login)]):
        try:
            note = store.create_note(space_id, request.title.strip(), request.content,
                                     request.source, account["display_name"], account["id"])
        except NotesNotFound as exc:
            raise HTTPException(status_code=404, detail=str(exc)) from exc
        return {"note": note}

    @router.patch("/api/notes/{note_id}")
    def update_note(note_id: str, request: NoteUpdate,
                    account: Annotated[dict[str, Any], Depends(require_login)]):
        try:
            note = store.update_note(note_id, request.title.strip(), request.content,
                                     account["display_name"], account["id"])
        except NotesNotFound as exc:
            raise HTTPException(status_code=404, detail=str(exc)) from exc
        return {"note": note}

    @router.post("/api/notes/spaces/{space_id}/links")
    def create_link(space_id: str, request: LinkCreate,
                    account: Annotated[dict[str, Any], Depends(require_login)]):
        try:
            link = store.create_link(space_id, request.source_note_id,
                                     request.target_note_id, request.label,
                                     account["display_name"])
        except ValueError as exc:
            raise HTTPException(status_code=422, detail=str(exc)) from exc
        return {"link": link}

    @router.post("/api/notes/spaces/{space_id}/messages")
    async def send_message(space_id: str, request: NoteMessageCreate,
                            account: Annotated[dict[str, Any], Depends(require_login)]):
        try:
            space = store.get_space(space_id, account["id"])
            messages = store.list_messages(space_id, account["id"])
            note = store.get_note(space_id, request.note_id, account["id"]) if request.note_id else None
            user = store.add_message(space_id, account["id"], note_id=request.note_id,
                                     author=account["display_name"], participant_id=None,
                                     content=request.content)
            context = [f"当前笔记空间：{space['title']}"]
            if space.get("description"):
                context.append(f"空间说明：{space['description']}")
            if note:
                context.append(f"当前笔记：{note['title']}\n{note['content']}")
            if messages:
                context.append("此前本空间对话：\n" + "\n".join(
                    f"{item['author']}：{item['content']}" for item in messages[-20:]
                ))
            context.append(f"本轮用户输入：{request.content}")
            agent_input = "\n\n".join(context)
            result = await service.send(agent_input, target=request.target,
                                        author=account["display_name"],
                                        author_account_id=account["id"])
            reply = result["reply"]
            assistant = store.add_message(space_id, account["id"], note_id=request.note_id,
                                          author=reply["author"],
                                          participant_id=reply.get("author_participant_id"),
                                          content=reply["content"], reply_to=user["id"],
                                          runtime_ref=reply.get("runtime_ref"))
            return {"user": user, "reply": assistant, "target": result.get("target"),
                    "participants": [p.to_dict() for p in service.participants.values()]}
        except NotesNotFound as exc:
            raise HTTPException(status_code=404, detail=str(exc)) from exc
        except ValueError as exc:
            raise HTTPException(status_code=422, detail=str(exc)) from exc
        except Exception as exc:
            raise HTTPException(
                status_code=502,
                detail=runtime_api_detail("消息已保存在笔记空间，但 Agent 暂不可用；可重试", exc),
            ) from exc

    @router.post("/api/notes/spaces/{space_id}/messages/{message_id}/retry")
    async def retry_message(space_id: str, message_id: str, request: NoteMessageRetry,
                             account: Annotated[dict[str, Any], Depends(require_login)]):
        try:
            original = store.get_message(message_id, account["id"])
            space = store.get_space(space_id, account["id"])
            messages = store.list_messages(space_id, account["id"])
            note = store.get_note(space_id, original["note_id"], account["id"]) if original["note_id"] else None
            context = [f"当前笔记空间：{space['title']}"]
            if space.get("description"):
                context.append(f"空间说明：{space['description']}")
            if note:
                context.append(f"当前笔记：{note['title']}\n{note['content']}")
            prior = [item for item in messages if item["id"] != message_id]
            if prior:
                context.append("此前本空间对话：\n" + "\n".join(
                    f"{item['author']}：{item['content']}" for item in prior[-20:]
                ))
            context.append(f"重试此前用户输入：{original['content']}")
            result = await service.send("\n\n".join(context), target=request.target,
                                        author=account["display_name"],
                                        author_account_id=account["id"])
            reply = result["reply"]
            assistant = store.add_message(space_id, account["id"], note_id=original["note_id"],
                                          author=reply["author"], participant_id=reply.get("author_participant_id"),
                                          content=reply["content"], reply_to=message_id,
                                          runtime_ref=reply.get("runtime_ref"))
            return {"original": original, "reply": assistant, "target": result.get("target"),
                    "retry_of": message_id}
        except NotesNotFound as exc:
            raise HTTPException(status_code=404, detail=str(exc)) from exc
        except ValueError as exc:
            raise HTTPException(status_code=422, detail=str(exc)) from exc
        except Exception as exc:
            raise HTTPException(
                status_code=502,
                detail=runtime_api_detail("原消息已保存，但 Agent 重试仍不可用；可再次重试", exc),
            ) from exc

    return router
