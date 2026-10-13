"""多用户身份：注册/登录/令牌，以及不同成员在同一项目里的发生归属。

范围：账号是普通软件对象（登录身份），不是 Subject/主体本体。
这些测试不证明 Cognos 的认知效果，只证明多人身份不再互相冒充。
"""

from __future__ import annotations

import json
import hashlib
import sys
import tempfile
import unittest
from dataclasses import replace
from pathlib import Path

from httpx import ASGITransport, AsyncClient

WEB_DIR = Path(__file__).resolve().parents[1]
PROJECT_DIR = WEB_DIR.parent
sys.path.insert(0, str(WEB_DIR))
sys.path.insert(0, str(PROJECT_DIR / "phase0"))

from codex_runtime import RuntimeReply
from server import AppConfig, Participant, create_app
from test_helpers import login_test_user


class FakeRuntime:
    def __init__(self, prefix: str) -> None:
        self.prefix = prefix
        self.thread_id = f"thread-{prefix}"

    async def send(self, message: str) -> RuntimeReply:
        return RuntimeReply(
            thread_id=self.thread_id,
            turn_id=f"turn-{self.prefix}",
            text=f"{self.prefix}：收到",
        )


def make_participants() -> dict[str, Participant]:
    return {
        "gemini": Participant("gemini", "Gemini", "Gemini 3.7", FakeRuntime("G")),
    }


class AuthTest(unittest.IsolatedAsyncioTestCase):
    def setUp(self) -> None:
        self.tmp = tempfile.TemporaryDirectory()
        root = Path(self.tmp.name)
        instructions = root / "participant.md"
        instructions.write_text("测试说明", encoding="utf-8")
        self.config = AppConfig(
            history_path=root / "vault" / "第一发生场.jsonl",
            thread_state_dir=root / "state",
            codex_cwd=root,
            participant_instructions_path=instructions,
        )
        self.app = create_app(self.config, participants=make_participants())

    def tearDown(self) -> None:
        self.tmp.cleanup()

    def raw_records(self) -> list[dict]:
        path = self.config.history_path
        if not path.exists():
            return []
        return [json.loads(line) for line in path.read_text(encoding="utf-8").splitlines() if line.strip()]

    async def client(self) -> AsyncClient:
        return AsyncClient(transport=ASGITransport(app=self.app), base_url="http://test")

    async def test_register_login_logout_round_trip(self) -> None:
        async with await self.client() as client:
            created = await client.post(
                "/api/auth/register",
                json={"username": "xiaolin", "password": "pass123", "display_name": "小林"},
            )
            self.assertEqual(200, created.status_code)
            self.assertIn("cognos_session", created.cookies)
            # 响应体不得包含任何口令材料
            body = json.dumps(created.json(), ensure_ascii=False)
            self.assertNotIn("pass123", body)
            self.assertNotIn("password_hash", body)

            me = await client.get("/api/auth/me")
            self.assertEqual("xiaolin", me.json()["account"]["username"])

            await client.post("/api/auth/logout")
            self.assertIsNone((await client.get("/api/auth/me")).json()["account"])

            ok = await client.post("/api/auth/login", json={"username": "xiaolin", "password": "pass123"})
            self.assertEqual(200, ok.status_code)
            bad = await client.post("/api/auth/login", json={"username": "xiaolin", "password": "wrongpass"})
            self.assertEqual(401, bad.status_code)

    async def test_duplicate_username_and_weak_password_are_rejected(self) -> None:
        async with await self.client() as client:
            short_name = await client.post(
                "/api/auth/register", json={"username": "li", "password": "pass123"}
            )
            self.assertEqual(422, short_name.status_code)  # 用户名过短
            weak = await client.post(
                "/api/auth/register", json={"username": "linx", "password": "abc"}
            )
            self.assertEqual(422, weak.status_code)  # 密码过短
            self.assertEqual(200, (await client.post(
                "/api/auth/register", json={"username": "linx", "password": "pass123"}
            )).status_code)
            dup = await client.post(
                "/api/auth/register", json={"username": "LINX", "password": "pass123"}
            )
            self.assertEqual(422, dup.status_code)  # 用户名大小写不敏感，不允许重复

    async def test_two_members_keep_their_own_authorship_in_one_project(self) -> None:
        async with await self.client() as client:
            await client.post("/api/auth/register",
                               json={"username": "xiaolin", "password": "pass123", "display_name": "小林"})
            await client.post("/api/auth/login", json={"username": "xiaolin", "password": "pass123"})
            org = (await client.post("/api/orgs", json={"name": "组织"})).json()["organization"]
            project = (await client.post(
                f"/api/orgs/{org['id']}/projects", json={"title": "项目"}
            )).json()["project"]

            first = await client.post(
                f"/api/projects/{project['id']}/messages",
                json={"content": "小林的问题", "target": "gemini", "author": "冒名作者"},
            )
            self.assertEqual(200, first.status_code)
            self.assertEqual("小林", first.json()["user"]["author"])

            # 第二个成员用独立 cookie 会话
            async with await self.client() as other:
                await other.post("/api/auth/register",
                                json={"username": "yuan", "password": "pass456", "display_name": "阿元"})
                await other.post("/api/auth/login", json={"username": "yuan", "password": "pass456"})
                yuan_account_id = (await other.get("/api/auth/me")).json()["account"]["id"]
                # 非成员不能靠"写一次"自动入组，也不能把自己加进组织。
                self.assertEqual(
                    403,
                    (await other.post(f"/api/projects/{project['id']}/messages",
                                      json={"content": "未入组时的写入"})).status_code,
                )
                self.assertEqual(
                    403,
                    (await other.post(f"/api/orgs/{org['id']}/members",
                                      json={"kind": "human", "account_id": yuan_account_id})).status_code,
                )
                # 由已在组织内的小林把阿元加入组织，再加入项目。
                yuan_member = (await client.post(f"/api/orgs/{org['id']}/members",
                                json={"kind": "human", "account_id": yuan_account_id})).json()["member"]
                self.assertEqual(200, (await client.post(f"/api/projects/{project['id']}/members",
                                json={"org_member_id": yuan_member["id"]})).status_code)
                second = await other.post(
                    f"/api/projects/{project['id']}/messages",
                    json={"content": "阿元的补充", "target": "gemini", "author": "小林"},
                )
                self.assertEqual(200, second.status_code)
                self.assertEqual("阿元", second.json()["user"]["author"])
                self.assertNotEqual(first.json()["user"]["author_account_id"],
                                    second.json()["user"]["author_account_id"])

            # 项目视图里两条人类发言者不同，且 AI 回复分别指向各自提问
            messages = (await client.get(f"/api/projects/{project['id']}/messages")).json()["messages"]
            human = [m for m in messages if m["author"] in ("小林", "阿元")]
            self.assertEqual(2, len(human))
            self.assertEqual({"小林", "阿元"}, {m["author"] for m in human})
            replies = [m for m in messages if m.get("author") == "Gemini"]
            self.assertEqual(2, len(replies))
            self.assertEqual({human[0]["id"], human[1]["id"]}, {r["in_reply_to"] for r in replies})

            raw = self.raw_records()
            self.assertEqual(["小林", "Gemini", "阿元", "Gemini"], [r["author"] for r in raw])
            members = (await client.get(f"/api/projects/{project['id']}/messages")).json()["members"]
            self.assertEqual({"小林", "阿元"}, {m["name"] for m in members})
            for member in members:
                detail = (await client.get(f"/api/members/{member['id']}")).json()
                self.assertEqual([member["account_id"]],
                                 [r["author_account_id"] for r in detail["authored"]])

    async def test_anonymous_mutations_are_rejected_and_account_reads_require_login(self) -> None:
        async with await self.client() as client:
            # 公开门面保持匿名可读；账号/项目数据必须登录。
            for path in ("/", "/activities", "/api/health", "/api/auth/me"):
                self.assertEqual(200, (await client.get(path)).status_code, path)
            for path in ("/api/bootstrap", "/api/instructions", "/api/orgs"):
                self.assertEqual(401, (await client.get(path)).status_code, path)
            # Enumerate the actual router, so a newly added unprotected write fails this boundary test.
            checked = 0
            for path, operations in self.app.openapi()["paths"].items():
                if not path.startswith("/api/") or path.startswith("/api/auth/"):
                    continue
                # Machine integrations authenticate with their own signed protocol,
                # not a Cognos browser session; their boundary is tested separately.
                if path == "/api/integrations/nextcloud-talk/webhook":
                    continue
                for method in {name.upper() for name in operations} & {"POST", "PATCH", "PUT", "DELETE"}:
                    import re
                    url = re.sub(r"\{[^}]+\}", "nonexistent", path)
                    response = await client.request(method, url, json={})
                    self.assertEqual(401, response.status_code, f"{method} {path}: {response.text}")
                    checked += 1
            self.assertGreater(checked, 20)
            self.assertFalse(self.config.history_path.exists())
            self.assertEqual([], self.app.state.org_store.list_organizations())

    async def test_auth_cookie_rotation_expiry_logout_and_app_recreation(self) -> None:
        async with await self.client() as client:
            created = await client.post("/api/auth/register", json={"username": "sessionuser", "password": "pass123"})
            cookie_header = created.headers["set-cookie"].lower()
            self.assertIn("httponly", cookie_header)
            self.assertIn("samesite=lax", cookie_header)
            self.assertIn("path=/", cookie_header)
            initial = created.cookies["cognos_session"]
            logged = await client.post("/api/auth/login", json={"username": "sessionuser", "password": "pass123"})
            current = logged.cookies["cognos_session"]
            self.assertNotEqual(initial, current)
            self.assertIsNone(self.app.state.auth_store.account_for_token(initial))
            reopened = create_app(self.config, participants=make_participants())
            self.assertIsNotNone(reopened.state.auth_store.account_for_token(current))
            with self.app.state.auth_store._conn() as conn:
                conn.execute("UPDATE auth_tokens SET expires_at = ? WHERE token_hash = ?",
                             ("2000-01-01T00:00:00+00:00", hashlib.sha256(current.encode()).hexdigest()))
            self.assertIsNone((await client.get("/api/auth/me")).json()["account"])
            self.assertEqual(401, (await client.post("/api/messages", json={"content": "过期不能写"})).status_code)
            logged = await client.post("/api/auth/login", json={"username": "sessionuser", "password": "pass123"})
            final = logged.cookies["cognos_session"]
            await client.post("/api/auth/logout")
            self.assertIsNone(self.app.state.auth_store.account_for_token(final))
            self.assertEqual(401, (await client.post("/api/messages", json={"content": "注销不能写"})).status_code)
            self.assertFalse(self.config.history_path.exists())

    async def test_password_validation_and_account_responses_never_leak_secrets(self) -> None:
        async with await self.client() as client:
            for payload in ({"username": "validname", "password": "abc"},
                            {"username": "validname", "password": {"secret": "nested-password"}},
                            {"username": "validname", "password": "p" * 129}):
                response = await client.post("/api/auth/register", json=payload)
                self.assertEqual(422, response.status_code)
                self.assertNotIn("input", response.text)
                self.assertNotIn("nested-password", response.text)
            password = "unique-private-password"
            created = await client.post("/api/auth/register", json={"username": "secretuser", "password": password})
            token = created.cookies["cognos_session"]
            with self.app.state.auth_store._conn() as conn:
                salt, digest = conn.execute("SELECT salt, password_hash FROM accounts").fetchone()
                token_digest = conn.execute("SELECT token_hash FROM auth_tokens").fetchone()[0]
            self.assertNotEqual(password, digest)
            self.assertEqual(hashlib.sha256(token.encode()).hexdigest(), token_digest)
            for response in (created, await client.get("/api/auth/me"), await client.get("/api/auth/accounts"),
                             await client.post("/api/auth/login", json={"username": "secretuser", "password": password})):
                for secret in (password, salt, digest, token, token_digest, "password_hash"):
                    self.assertNotIn(secret, response.text)
            self.assertFalse(self.config.history_path.exists())

    async def test_secure_cookie_option_requires_https_transport(self) -> None:
        app = create_app(replace(self.config, auth_cookie_secure=True), participants=make_participants())
        async with AsyncClient(transport=ASGITransport(app=app), base_url="https://test") as client:
            created = await client.post("/api/auth/register", json={"username": "secureuser", "password": "pass123"})
            self.assertIn("secure", created.headers["set-cookie"].lower())
            self.assertIsNotNone((await client.get("/api/auth/me")).json()["account"])
            self.assertEqual(200, (await client.post("/api/messages", json={"content": "HTTPS cookie"})).status_code)

    async def test_accounts_from_separate_apps_cannot_share_sessions(self) -> None:
        other_root = Path(self.tmp.name) / "other"
        other_app = create_app(replace(self.config, thread_state_dir=other_root / "state",
                                      history_path=other_root / "raw.jsonl"), participants=make_participants())
        async with await self.client() as client:
            created = await client.post("/api/auth/register", json={"username": "isolateduser", "password": "pass123"})
            token = created.cookies["cognos_session"]
        self.assertIsNone(other_app.state.auth_store.account_for_token(token))
        async with AsyncClient(transport=ASGITransport(app=other_app), base_url="http://test") as client:
            client.cookies.set("cognos_session", token)
            response = await client.post("/api/messages", json={"content": "不能进入另一个auth store"})
            self.assertEqual(401, response.status_code)

    async def test_every_message_entry_uses_session_and_preserves_legacy_raw_bytes(self) -> None:
        legacy = b'{"id": "legacy", "author": "legacy author", "content": "old raw", "unknown": [1, 2]}\n'
        self.config.history_path.parent.mkdir(parents=True, exist_ok=True)
        self.config.history_path.write_bytes(legacy)
        async with await self.client() as client:
            await login_test_user(client, display_name="真实成员")
            account = (await client.get("/api/auth/me")).json()["account"]
            org = (await client.post("/api/orgs", json={"name": "组织"})).json()["organization"]
            project = (await client.post(f"/api/orgs/{org['id']}/projects", json={"title": "项目"})).json()["project"]
            channel = (await client.get(f"/api/projects/{project['id']}/conversations")).json()["default_conversation_id"]
            for path in ("/api/messages", f"/api/projects/{project['id']}/messages", f"/api/conversations/{channel}/messages"):
                response = await client.post(path, json={"content": "新消息", "author": "伪装其他同学"})
                self.assertEqual(200, response.status_code, response.text)
                self.assertEqual("真实成员", response.json()["user"]["author"])
                self.assertEqual(account["id"], response.json()["user"]["author_account_id"])
                self.assertEqual("Gemini", response.json()["reply"]["author"])
            inquiry = await client.post(f"/api/projects/{project['id']}/inquiries", json={"question": "新问题", "author": "伪装作者"})
            self.assertEqual("真实成员", inquiry.json()["user_record"]["author"])
            attempt = await client.post(f"/api/inquiries/{inquiry.json()['inquiry']['id']}/attempts",
                                        json={"content": "再展开", "author": "伪装作者"})
            self.assertEqual(200, attempt.status_code, attempt.text)
            self.assertEqual("真实成员", attempt.json()["user"]["author"])
            activity = (await client.post(f"/api/projects/{project['id']}/activities", json={})).json()["activity"]
            published = await client.post(f"/api/activities/{activity['id']}/publish",
                json={"request_id": "publish-auth", "kind": "message", "content": "发布消息", "author": "伪装作者"})
            self.assertEqual("真实成员", published.json()["published"]["object"]["author"])
            continued = await client.post(f"/api/activities/{activity['id']}/continue",
                json={"request_id": "continue-auth", "revision": activity["revision"], "content": "继续活动", "initiator": "伪装作者"})
            self.assertEqual(200, continued.status_code, continued.text)
            raw = self.raw_records()
            continued_user = next(r for r in raw if r["content"] == "继续活动")
            self.assertEqual("真实成员", continued_user["author"])
            self.assertEqual(account["id"], continued_user["author_account_id"])
            self.assertTrue(self.config.history_path.read_bytes().startswith(legacy))

    async def test_linked_members_are_idempotent_and_same_names_do_not_merge(self) -> None:
        async with await self.client() as first, await self.client() as second:
            await login_test_user(first, username="firstuser", display_name="同名")
            await login_test_user(second, username="seconduser", display_name="同名")
            first_account = (await first.get("/api/auth/me")).json()["account"]
            second_account = (await second.get("/api/auth/me")).json()["account"]
            org = (await first.post("/api/orgs", json={"name": "组织"})).json()["organization"]
            project = (await first.post(f"/api/orgs/{org['id']}/projects", json={"title": "项目"})).json()["project"]
            self.app.state.history.append_message(author="同名", content="没有登录身份的旧记录")
            # 链接账号到成员是组织内操作：尚未入组的第二账号不能给自己开位子。
            self.assertEqual(
                403,
                (await second.post(f"/api/orgs/{org['id']}/members",
                                   json={"kind": "human", "account_id": second_account["id"]})).status_code,
            )
            for client, account in ((first, first_account), (second, second_account)):
                linked = await first.post(f"/api/orgs/{org['id']}/members",
                                          json={"kind": "human", "account_id": account["id"]})
                self.assertEqual(200, linked.status_code, linked.text)
                member = linked.json()["member"]
                self.assertEqual("同名", member["name"])
                self.assertEqual(200, (await first.post(f"/api/projects/{project['id']}/members",
                                                        json={"org_member_id": member["id"]})).status_code)
                await client.post(f"/api/projects/{project['id']}/messages", json={"content": account["username"]})
                again = await first.post(f"/api/orgs/{org['id']}/members",
                                         json={"kind": "human", "account_id": account["id"]})
                self.assertEqual(member["id"], again.json()["member"]["id"])
                detail = (await client.get(f"/api/members/{member['id']}")).json()
                self.assertEqual([account["username"]], [r["content"] for r in detail["authored"]])

    async def test_accounts_listing_requires_login(self) -> None:
        async with await self.client() as client:
            anonymous = await client.get("/api/auth/accounts")
            self.assertEqual(401, anonymous.status_code)
            await client.post("/api/auth/register", json={"username": "linb", "password": "pass123"})
            await client.post("/api/auth/login", json={"username": "linb", "password": "pass123"})
            listed = await client.get("/api/auth/accounts")
            self.assertEqual(200, listed.status_code)
            self.assertEqual(["linb"], [a["username"] for a in listed.json()["accounts"]])


if __name__ == "__main__":
    unittest.main()
