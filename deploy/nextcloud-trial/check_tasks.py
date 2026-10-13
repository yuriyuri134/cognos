"""Live Deck API journey, never a mock. Only the isolated loopback trial is allowed.

Creates uniquely named trial accounts and a board. Leaves them for UI/restart checks.
Credentials stay in .secrets; evidence has IDs/checks only. This is API evidence,
not proof that browser, invitation, timeline, or the whole J07 journey passes.
"""
from __future__ import annotations

import argparse
import json
import secrets
from datetime import datetime, timezone
from pathlib import Path

import httpx

ROOT = Path(__file__).resolve().parent
BASE = "http://127.0.0.1:8780"
DECK = "/index.php/apps/deck/api/v1.0"
HEADERS = {"OCS-APIRequest": "true", "Accept": "application/json"}


def request(client, method, path, *, allowed=(200, 201), **kwargs):
    response = client.request(method, path, **kwargs)
    if response.status_code not in allowed:
        # Do not echo upstream body or credentials, even on an auth error.
        raise RuntimeError(f"{method} {path}: HTTP {response.status_code}")
    return response


def ocs(client, method, path, **kwargs):
    response = request(client, method, path, params={"format": "json"}, **kwargs)
    payload = response.json()["ocs"]
    if int(payload["meta"]["statuscode"]) not in (100, 200):
        raise RuntimeError(f"{method} {path}: OCS {payload['meta']['statuscode']}")
    return payload["data"]


def client_for(user, password):
    return httpx.Client(base_url=BASE, auth=(user, password), headers=HEADERS,
                        timeout=30, trust_env=False, follow_redirects=False)


def save_state(path, state):
    temporary = path.with_suffix(".tmp")
    temporary.write_text(json.dumps(state), encoding="utf-8")
    temporary.replace(path)


def check_shared_board(state, report):
    board_path = f"{DECK}/boards/{state['board']}"
    card_path = f"{board_path}/stacks/{state['stack']}/cards/{state['card']}"
    for role in ("alice", "bob"):
        credentials = state[role]
        with client_for(credentials["user"], credentials["password"]) as client:
            card = request(client, "GET", card_path).json()
            if card["description"] != "同伴已完成整理，刷新后保留。":
                raise RuntimeError(f"{role}: persisted task result missing")
            assigned = [item["participant"]["uid"] for item in card.get("assignedUsers") or []]
            if state["bob"]["user"] not in assigned:
                raise RuntimeError(f"{role}: assignment was lost")
            if state.get("completed_stack") is not None and int(card["stackId"]) != state["completed_stack"]:
                raise RuntimeError(f"{role}: completed column was not persisted")
            report["checks"].append(f"{role}: task result and assignee persist")
    credentials = state["outsider"]
    with client_for(credentials["user"], credentials["password"]) as outsider:
        for method, path, kwargs in (
            ("GET", board_path, {}),
            ("PUT", card_path + "/assignUser", {"json": {"userId": credentials["user"]}}),
        ):
            response = outsider.request(method, path, **kwargs)
            if response.status_code not in (403, 404):
                raise RuntimeError(f"outsider {method}: expected refusal, got {response.status_code}")
        report["checks"].append("outsider: board read and task write denied")


def run(verify: bool):
    secret_dir = ROOT / ".secrets"
    state_path = secret_dir / "task-journey.json"
    evidence_dir = ROOT / "evidence"
    evidence_dir.mkdir(exist_ok=True)
    report = {"at": datetime.now(timezone.utc).isoformat(), "scope": "live Deck API only",
              "checks": [], "browser": "unverified", "service_restart": "unverified",
              "timeline": "unverified", "full_journey": "unverified"}
    try:
        if verify:
            state = json.loads(state_path.read_text(encoding="utf-8"))
        else:
            # A second create must not discard credentials for an existing trial.
            if state_path.exists():
                raise RuntimeError("Saved trial already exists; use --verify or explicitly archive its state first")
            password = (secret_dir / "admin-password").read_text(encoding="utf-8").strip()
            suffix = secrets.token_hex(4)
            state = {"trial": suffix}
            for role in ("alice", "bob", "outsider"):
                state[role] = {"user": f"journey-{role}-{suffix}", "password": secrets.token_hex(24)}
            # Save before any write: if provisioning fails, the trial accounts remain identifiable.
            with client_for("cognos-admin", password) as admin:
                status = request(admin, "GET", "/status.php").json()
                if not status.get("installed") or status.get("maintenance"):
                    raise RuntimeError("Nextcloud is not installed or is in maintenance")
                report["server_version"] = status.get("versionstring")
                save_state(state_path, state)
                for role in ("alice", "bob", "outsider"):
                    cred = state[role]
                    ocs(admin, "POST", "/ocs/v1.php/cloud/users", data={
                        "userid": cred["user"], "password": cred["password"],
                        "displayName": {"alice": "试用同学 A", "bob": "试用同学 B", "outsider": "试用非成员"}[role],
                    })
            a, b = state["alice"], state["bob"]
            with client_for(a["user"], a["password"]) as alice:
                board = request(alice, "POST", f"{DECK}/boards", json={
                    "title": f"Cognos 协作旅程 {suffix}", "color": "317CCC"}).json()
                state["board"] = board["id"]
                save_state(state_path, state)
                board_path = f"{DECK}/boards/{board['id']}"
                request(alice, "POST", board_path + "/acl", json={
                    "type": 0, "participant": b["user"], "permissionEdit": True,
                    "permissionShare": False, "permissionManage": False})
                stack = request(alice, "POST", board_path + "/stacks", json={
                    "title": "待处理", "order": 1}).json()
                state["stack"] = stack["id"]
                state["stacks"] = {"待处理": stack["id"]}
                for order, title in enumerate(("进行中", "已完成"), start=2):
                    column = request(alice, "POST", board_path + "/stacks", json={
                        "title": title, "order": order}).json()
                    state["stacks"][title] = int(column["id"])
                    save_state(state_path, state)
                card = request(alice, "POST", f"{board_path}/stacks/{stack['id']}/cards", json={
                    "title": "整理项目材料", "type": "plain", "order": 1,
                    "description": "由 A 创建并指派给 B。"}).json()
                state["card"] = card["id"]
                save_state(state_path, state)
                card_path = f"{board_path}/stacks/{stack['id']}/cards/{card['id']}"
                request(alice, "PUT", card_path + "/assignUser", json={"userId": b["user"]})
                request(alice, "PUT", card_path + "/assignUser", allowed=(400,),
                        json={"userId": state["outsider"]["user"]})
                after_refusal = request(alice, "GET", card_path).json()
                assigned = [item["participant"]["uid"] for item in after_refusal.get("assignedUsers") or []]
                if assigned != [b["user"]]:
                    raise RuntimeError("Rejected assignment changed the card")
                report["checks"].append("alice: assigning a non-member is rejected without changing assignee")
            with client_for(b["user"], b["password"]) as bob:
                visible = request(bob, "GET", f"{DECK}/boards").json()
                if not any(item["id"] == state["board"] for item in visible):
                    raise RuntimeError("Bob cannot find the shared board")
                report["checks"].append("bob: shared board is discoverable")
                # Deck PUT requires the complete editable card, unlike Cognos PATCH.
                current = request(bob, "GET", card_path).json()
                owner = current["owner"]
                if isinstance(owner, dict):
                    owner = owner["uid"]
                request(bob, "PUT", card_path, json={
                    "title": current["title"], "description": "同伴已完成整理，刷新后保留。",
                    "type": current["type"], "owner": owner, "order": current["order"],
                    "duedate": current.get("duedate"), "archived": False,
                })
                report["checks"].append("bob: assigned task is editable")
                # Deck expresses this workflow with columns. Check each saved move,
                # including assignment preservation, rather than equating text with done.
                for title in ("进行中", "已完成"):
                    destination = state["stacks"][title]
                    request(bob, "PUT", card_path + "/reorder", json={
                        "order": 1, "stackId": destination})
                    state["stack"] = destination
                    if title == "已完成":
                        state["completed_stack"] = destination
                    save_state(state_path, state)
                    card_path = f"{board_path}/stacks/{destination}/cards/{state['card']}"
                    moved = request(bob, "GET", card_path).json()
                    if int(moved["stackId"]) != destination:
                        raise RuntimeError("Task column change was not persisted")
                    assigned = [item["participant"]["uid"] for item in moved.get("assignedUsers") or []]
                    if b["user"] not in assigned:
                        raise RuntimeError("Column change lost assignee")
                    report["checks"].append(f"bob: task moved to {title}; assignee preserved")
        check_shared_board(state, report)
        report["api_result"] = "passed"
        report["objects"] = {key: state[key] for key in ("board", "stack", "card")}
    except Exception as exc:
        report["api_result"] = "failed"
        # Exception strings intentionally omitted: provider responses can contain auth input.
        report["failure_type"] = type(exc).__name__
        raise
    finally:
        (evidence_dir / ("task-verify.json" if verify else "task-create.json")).write_text(
            json.dumps(report, ensure_ascii=False, indent=2), encoding="utf-8")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--verify", action="store_true", help="Read-only verify of the saved trial after restart")
    args = parser.parse_args()
    try:
        run(args.verify)
    except Exception as exc:
        raise SystemExit(f"API journey failed ({type(exc).__name__}); inspect evidence, not complete.")
    print("Live API checks passed. Browser, personal task list and historical events still require acceptance.")
