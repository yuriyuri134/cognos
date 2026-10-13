"""Session dependencies: authenticated software accounts, never raw identities."""

from typing import Any

from fastapi import Cookie, HTTPException, Request

from auth_store import AuthStore

COOKIE_NAME = "cognos_session"


def get_auth_store(request: Request) -> AuthStore:
    return request.app.state.auth_store


async def optional_login(
    request: Request,
    cognos_session: str | None = Cookie(default=None, alias=COOKIE_NAME),
) -> dict[str, Any] | None:
    return get_auth_store(request).account_for_token(cognos_session or "")


async def require_login(
    request: Request,
    cognos_session: str | None = Cookie(default=None, alias=COOKIE_NAME),
) -> dict[str, Any]:
    """Return the public account from this app's session store or reject writes."""
    account = await optional_login(request, cognos_session)
    if account is None:
        raise HTTPException(status_code=401, detail="需要登录，会话无效或已过期")
    return account


def join_project(store: Any, project_id: str, account: dict[str, Any]) -> dict[str, Any]:
    """Bootstrap helper, NOT an authorization check.

    Only for the moment an account founds a project; every other route must use
    require_project_member()/require_org_member() below, because auto-joining on
    activity would let an outsider grant themselves access by writing once.
    """
    project = store.get_project(project_id)
    if project is None:
        raise HTTPException(status_code=404, detail="项目不存在")
    member = store.ensure_account_member(project["org_id"], account)
    store.add_project_member(project_id, member["id"])
    return member


def require_org_member(store: Any, org_id: str, account: dict[str, Any]) -> dict[str, Any]:
    """Caller must already be a member of this organization. Never auto-joins."""
    org = store.get_organization(org_id)
    if org is None:
        raise HTTPException(status_code=404, detail="组织不存在")
    member = store.account_member(org_id, account["id"])
    if member is None:
        raise HTTPException(status_code=403, detail="你不是该组织成员")
    return member


def require_project_member(store: Any, project_id: str, account: dict[str, Any]) -> dict[str, Any]:
    """Caller must already be a member of this project. Never auto-joins."""
    project = store.get_project(project_id)
    if project is None:
        raise HTTPException(status_code=404, detail="项目不存在")
    member = store.account_member(project["org_id"], account["id"])
    if member is None or not any(item["id"] == member["id"]
                                 for item in store.list_project_members(project_id)):
        raise HTTPException(status_code=403, detail="你不是该项目成员")
    return project
