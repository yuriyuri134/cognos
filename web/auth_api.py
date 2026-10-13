"""登录 / 账号 API（薄层）。

原则：
- 登录身份是普通软件对象，不是 Subject/主体本体。
- 令牌通过 HttpOnly Cookie 传递；前端 JS 不持有明文令牌。
- 这里不写任何 raw occurrence；登录不进入第一发生场.jsonl。
"""

from __future__ import annotations

from typing import Any

from fastapi import APIRouter, Cookie, HTTPException, Query
from fastapi.responses import JSONResponse
from pydantic import BaseModel, Field

from auth_store import AuthError, AuthStore
from auth_middleware import COOKIE_NAME

_COOKIE_MAX_AGE = 14 * 24 * 3600


class RegisterRequest(BaseModel):
    username: str = Field(..., min_length=3, max_length=32)
    password: str = Field(..., min_length=6, max_length=128)
    display_name: str = Field(default="", max_length=80)


class LoginRequest(BaseModel):
    username: str = Field(..., min_length=1, max_length=64)
    password: str = Field(..., min_length=1, max_length=128)


def create_auth_router(store: AuthStore, *, cookie_secure: bool = False) -> APIRouter:
    router = APIRouter()

    def _set_cookie(response: JSONResponse, token: str) -> None:
        response.set_cookie(
            COOKIE_NAME,
            token,
            max_age=_COOKIE_MAX_AGE,
            httponly=True,
            samesite="lax",
            secure=cookie_secure,
            path="/",
        )

    def _require(token: str | None) -> dict[str, Any]:
        account = store.account_for_token(token or "")
        if account is None:
            raise HTTPException(status_code=401, detail="需要登录")
        return account

    @router.post("/api/auth/register")
    def register(request: RegisterRequest,
                 cognos_session: str | None = Cookie(default=None, alias=COOKIE_NAME)) -> JSONResponse:
        try:
            account = store.create_account(request.username, request.password, request.display_name)
        except AuthError as exc:
            raise HTTPException(status_code=422, detail=str(exc)) from exc
        token = store.issue_token(account["id"])
        if cognos_session:
            store.logout_token(cognos_session)
        response = JSONResponse({"account": account})
        _set_cookie(response, token)
        return response

    @router.post("/api/auth/login")
    def login(request: LoginRequest,
              cognos_session: str | None = Cookie(default=None, alias=COOKIE_NAME)) -> JSONResponse:
        account = store.verify_login(request.username, request.password)
        if account is None:
            raise HTTPException(status_code=401, detail="用户名或密码不正确")
        token = store.issue_token(account["id"])
        if cognos_session:
            store.logout_token(cognos_session)
        response = JSONResponse({"account": account})
        _set_cookie(response, token)
        return response

    @router.post("/api/auth/logout")
    def logout(cognos_session: str | None = Cookie(default=None, alias=COOKIE_NAME)) -> JSONResponse:
        if cognos_session:
            store.logout_token(cognos_session)
        response = JSONResponse({"status": "ok"})
        response.delete_cookie(COOKIE_NAME, path="/", httponly=True, samesite="lax", secure=cookie_secure)
        return response

    @router.get("/api/auth/me")
    def me(cognos_session: str | None = Cookie(default=None, alias=COOKIE_NAME)) -> dict[str, Any]:
        if not cognos_session:
            return {"account": None}
        return {"account": store.account_for_token(cognos_session)}

    @router.get("/api/auth/accounts")
    def accounts(
        q: str = Query(default="", max_length=80),
        cognos_session: str | None = Cookie(default=None, alias=COOKIE_NAME),
    ) -> dict[str, Any]:
        """已登录成员目录；q 支持按用户名或显示名搜索。"""
        _require(cognos_session)
        query = q.strip().casefold()
        accounts = store.list_accounts()
        if query:
            accounts = [
                account for account in accounts
                if query in account["username"].casefold()
                or query in account["display_name"].casefold()
            ]
        return {"accounts": accounts}

    return router
