"""Organization v0 API 测试；用假运行时，不访问网络。

覆盖：组织/项目/成员 CRUD、项目内消息作用域、跨项目引用、AI 成员校验。
"""

from __future__ import annotations

import sys
import tempfile
import unittest
from contextlib import asynccontextmanager
from test_helpers import login_test_user
from pathlib import Path

from httpx import ASGITransport, AsyncClient


WEB_DIR = Path(__file__).resolve().parents[1]
PROJECT_DIR = WEB_DIR.parent
PHASE0_DIR = PROJECT_DIR / "phase0"
sys.path.insert(0, str(WEB_DIR))
sys.path.insert(0, str(PHASE0_DIR))

from codex_runtime import RuntimeReply
from server import AppConfig, Participant, create_app


class FakeRuntime:
    def __init__(self, prefix: str = "fake", thread_id: str = "thread-org") -> None:
        self.prefix = prefix
        self.thread_id = thread_id

    async def send(self, message: str) -> RuntimeReply:
        return RuntimeReply(
            thread_id=self.thread_id,
            turn_id=f"turn-{self.prefix}-{len(message)}",
            text=f"{self.prefix} 回复：{message}",
        )


def make_participants() -> dict[str, Participant]:
    return {
        "gemini": Participant(
            id="gemini",
            name="Gemini",
            model_name="Gemini 3.7 Flash",
            runtime=FakeRuntime(prefix="G", thread_id="thread-gemini-org"),
        ),
        "gemini-b": Participant(
            id="gemini-b",
            name="Gemini-B",
            model_name="Gemini 2.5 Flash",
            runtime=FakeRuntime(prefix="B", thread_id="thread-gemini-b-org"),
        ),
    }


class OrgApiTest(unittest.IsolatedAsyncioTestCase):
    def setUp(self) -> None:
        self.temporary_directory = tempfile.TemporaryDirectory()
        root = Path(self.temporary_directory.name)
        instructions = root / "participant.md"
        instructions.write_text("测试说明", encoding="utf-8")
        self.config = AppConfig(
            history_path=root / "vault" / "first-field.jsonl",
            thread_state_dir=root / "state",
            codex_cwd=root,
            participant_instructions_path=instructions,
        )
        self.app = create_app(self.config, participants=make_participants())

    def tearDown(self) -> None:
        self.temporary_directory.cleanup()

    @asynccontextmanager
    async def client(self):
        async with AsyncClient(transport=ASGITransport(app=self.app), base_url="http://test") as client:
            await login_test_user(client)
            yield client

    async def create_org_and_project(self):
        async with self.client() as client:
            org_res = await client.post(
                "/api/orgs", json={"name": "Cognos 组织", "description": "第一个组织"}
            )
            org_id = org_res.json()["organization"]["id"]
            project_res = await client.post(
                f"/api/orgs/{org_id}/projects",
                json={"title": "第一真实项目", "description": "项目内沟通"},
            )
            project_id = project_res.json()["project"]["id"]
            return org_id, project_id

    async def test_org_project_member_round_trip(self) -> None:
        async with self.client() as client:
            # 创建组织
            org_res = await client.post("/api/orgs", json={"name": "测试组织"})
            self.assertEqual(200, org_res.status_code)
            org_id = org_res.json()["organization"]["id"]

            # 创建项目
            project_res = await client.post(
                f"/api/orgs/{org_id}/projects", json={"title": "项目 A"}
            )
            self.assertEqual(200, project_res.status_code)
            project_id = project_res.json()["project"]["id"]

            # 添加人类成员 + AI 成员
            human_res = await client.post(
                f"/api/orgs/{org_id}/members",
                json={"kind": "human", "name": "小林"},
            )
            ai_res = await client.post(
                f"/api/orgs/{org_id}/members",
                json={"kind": "ai", "name": "Gemini", "participant_id": "gemini"},
            )
            self.assertEqual(200, human_res.status_code)
            self.assertEqual(200, ai_res.status_code)
            human_id = human_res.json()["member"]["id"]
            ai_id = ai_res.json()["member"]["id"]

            # 把两个成员加入项目
            for member_id in (human_id, ai_id):
                add_res = await client.post(
                    f"/api/projects/{project_id}/members",
                    json={"org_member_id": member_id},
                )
                self.assertEqual(200, add_res.status_code)

            # 组织详情包含项目与成员
            org_detail = (await client.get(f"/api/orgs/{org_id}")).json()
            self.assertEqual(1, len(org_detail["projects"]))
            # 创建者登录账号自动入组织；显式旧式成员不按同名合并。
            self.assertEqual(3, len(org_detail["members"]))
            self.assertIsNotNone(org_detail["members"][0]["account_id"])

            # 项目消息视图返回成员
            messages = (await client.get(f"/api/projects/{project_id}/messages")).json()
            self.assertEqual(3, len(messages["members"]))
            self.assertEqual("Gemini", messages["members"][-1]["name"])
            self.assertEqual("ai", messages["members"][-1]["kind"])

    async def test_project_messages_are_scoped_but_global_field_keeps_all(self) -> None:
        org_id, project_id = await self.create_org_and_project()
        async with self.client() as client:
            # 项目内发一条消息
            sent = await client.post(
                f"/api/projects/{project_id}/messages",
                json={"content": "项目内的问题", "target": "gemini"},
            )
            self.assertEqual(200, sent.status_code)
            self.assertEqual(project_id, sent.json()["project_id"])
            self.assertEqual(project_id, sent.json()["user"]["project_id"])
            self.assertEqual(project_id, sent.json()["reply"]["project_id"])

            # 项目视图只看到本项目发生
            scoped = (await client.get(f"/api/projects/{project_id}/messages")).json()
            self.assertEqual(2, len(scoped["messages"]))

            # bootstrap 仅返回该账号有成员身份的项目的消息
            global_field = (await client.get("/api/bootstrap")).json()
            self.assertEqual(2, len(global_field["messages"]))
            self.assertTrue(
                all(m.get("project_id") == project_id for m in global_field["messages"])
            )

    async def test_project_messages_require_membership(self) -> None:
        org_id, project_id = await self.create_org_and_project()
        async with AsyncClient(transport=ASGITransport(app=self.app), base_url="http://test") as owner:
            await login_test_user(owner, username="project_read_owner", display_name="Owner")
            # 未入组前写入必须被拒绝（此前靠 join_project() 自动加入才返回 200）。
            self.assertEqual(
                403,
                (await owner.post(f"/api/projects/{project_id}/messages",
                                  json={"content": "尚未入组", "target": "gemini"})).status_code,
            )
            # 未入组者不能给自己开位子；由已在组织内的账号走正式入组路径。
            owner_account = (await owner.get("/api/auth/me")).json()["account"]
            self.assertEqual(
                403,
                (await owner.post(f"/api/orgs/{org_id}/members",
                                  json={"kind": "human", "account_id": owner_account["id"]})).status_code,
            )
            async with self.client() as admin:
                seat = (await admin.post(f"/api/orgs/{org_id}/members",
                                         json={"kind": "human", "account_id": owner_account["id"]})).json()["member"]
                self.assertEqual(200, (await admin.post(f"/api/projects/{project_id}/members",
                                                        json={"org_member_id": seat["id"]})).status_code)
            sent = await owner.post(
                f"/api/projects/{project_id}/messages",
                json={"content": "成员可见的讨论", "target": "gemini"},
            )
            self.assertEqual(200, sent.status_code)
        async with AsyncClient(transport=ASGITransport(app=self.app), base_url="http://test") as outsider:
            await login_test_user(outsider, username="project_read_outsider", display_name="Outsider")
            denied = await outsider.get(f"/api/projects/{project_id}/messages")
            self.assertEqual(403, denied.status_code)

    async def test_two_projects_are_isolated(self) -> None:
        org_id, project_a = await self.create_org_and_project()
        async with self.client() as client:
            project_b_res = await client.post(
                f"/api/orgs/{org_id}/projects", json={"title": "项目 B"}
            )
            project_b = project_b_res.json()["project"]["id"]

            await client.post(
                f"/api/projects/{project_a}/messages",
                json={"content": "A 的讨论", "target": "gemini"},
            )
            view_b = (await client.get(f"/api/projects/{project_b}/messages")).json()
            self.assertEqual([], view_b["messages"])

    async def test_cross_project_quote_references_global_occurrence(self) -> None:
        org_id, project_a = await self.create_org_and_project()
        async with self.client() as client:
            project_b_res = await client.post(
                f"/api/orgs/{org_id}/projects", json={"title": "项目 B"}
            )
            project_b = project_b_res.json()["project"]["id"]

            # A 项目中产生一条 AI 回复
            sent_a = await client.post(
                f"/api/projects/{project_a}/messages",
                json={"content": "这是 A 的判断", "target": "gemini"},
            )
            reply_a_id = sent_a.json()["reply"]["id"]

            # B 项目中引用 A 的发生（跨项目引用必须成立）
            sent_b = await client.post(
                f"/api/projects/{project_b}/messages",
                json={
                    "content": "面对 A 的判断",
                    "target": "gemini-b",
                    "quoted_occurrence_id": reply_a_id,
                },
            )
            self.assertEqual(200, sent_b.status_code)
            self.assertEqual(reply_a_id, sent_b.json()["user"]["quoted_occurrence_id"])
            self.assertEqual(project_b, sent_b.json()["user"]["project_id"])

            # B 视图返回被引用但不在本流里的发生，供前端展示
            view_b = (await client.get(f"/api/projects/{project_b}/messages")).json()
            self.assertIn(reply_a_id, view_b["quoted"])
            self.assertEqual("Gemini", view_b["quoted"][reply_a_id]["author"])

    async def test_ai_member_requires_existing_participant(self) -> None:
        org_id, _ = await self.create_org_and_project()
        async with self.client() as client:
            missing = await client.post(
                f"/api/orgs/{org_id}/members",
                json={"kind": "ai", "name": "幽灵", "participant_id": "not-registered"},
            )
            self.assertEqual(422, missing.status_code)

            no_participant = await client.post(
                f"/api/orgs/{org_id}/members",
                json={"kind": "ai", "name": "无绑定"},
            )
            self.assertEqual(422, no_participant.status_code)

    async def test_project_discussion_reaches_agent_and_returns_in_project_stream(self) -> None:
        org_id, project_id = await self.create_org_and_project()
        async with self.client() as client:
            add_ai = await client.post(
                f"/api/orgs/{org_id}/members",
                json={"kind": "ai", "name": "Gemini", "participant_id": "gemini"},
            )
            self.assertEqual(200, add_ai.status_code)
            await client.post(
                f"/api/projects/{project_id}/members",
                json={"org_member_id": add_ai.json()["member"]["id"]},
            )
            sent = await client.post(
                f"/api/projects/{project_id}/messages",
                json={"content": "请参与项目讨论", "target": "gemini"},
            )
            self.assertEqual(200, sent.status_code)
            self.assertEqual("Gemini", sent.json()["reply"]["author"])
            stream = (await client.get(f"/api/projects/{project_id}/messages")).json()
            self.assertEqual(["小林", "Gemini"], [item["author"] for item in stream["messages"]])
            self.assertEqual("gemini", stream["participants"][0]["id"])

    async def test_unknown_org_project_return_404(self) -> None:
        async with self.client() as client:
            self.assertEqual(404, (await client.get("/api/orgs/nope")).status_code)
            self.assertEqual(
                404,
                (
                    await client.post(
                        "/api/orgs/nope/projects", json={"title": "x"}
                    )
                ).status_code,
            )
            self.assertEqual(
                404,
                (await client.get("/api/projects/nope/messages")).status_code,
            )
            self.assertEqual(
                404,
                (
                    await client.post(
                        "/api/projects/nope/messages", json={"content": "x"}
                    )
                ).status_code,
            )

    async def test_cross_org_project_member_is_rejected(self) -> None:
        org_a, project_a = await self.create_org_and_project()
        async with self.client() as client:
            org_b = (
                await client.post("/api/orgs", json={"name": "另一个组织"})
            ).json()["organization"]["id"]
            member_b = (
                await client.post(
                    f"/api/orgs/{org_b}/members", json={"kind": "human", "name": "外人"}
                )
            ).json()["member"]["id"]
            add_res = await client.post(
                f"/api/projects/{project_a}/members",
                json={"org_member_id": member_b},
            )
            self.assertEqual(422, add_res.status_code)
            self.assertEqual(200, (await client.get(f"/api/orgs/{org_a}")).status_code)

    async def test_member_role_and_project_position(self) -> None:
        org_id, project_id = await self.create_org_and_project()
        async with self.client() as client:
            member_res = await client.post(
                f"/api/orgs/{org_id}/members",
                json={"kind": "ai", "name": "Gemini", "participant_id": "gemini", "org_role": "架构判断"},
            )
            self.assertEqual(200, member_res.status_code)
            member_id = member_res.json()["member"]["id"]
            await client.post(
                f"/api/projects/{project_id}/members", json={"org_member_id": member_id}
            )

            # 设定项目内负责位置
            pos = await client.patch(
                f"/api/projects/{project_id}/members/{member_id}",
                json={"position": "接口与发生层负责人"},
            )
            self.assertEqual(200, pos.status_code)
            # 更新组织职位/职责 + 专属资料
            upd = await client.patch(
                f"/api/orgs/{org_id}/members/{member_id}",
                json={
                    "org_role": "认识论与工程衔接",
                    "materials": "参考：决策与残余.md；当前边界约束清单。",
                },
            )
            self.assertEqual(200, upd.status_code)

            detail = (await client.get(f"/api/members/{member_id}")).json()
            member = detail["member"]
            self.assertEqual("认识论与工程衔接", member["org_role"])
            self.assertEqual("参考：决策与残余.md；当前边界约束清单。", member["materials"])
            self.assertEqual(1, len(member["projects"]))
            self.assertEqual("接口与发生层负责人", member["projects"][0]["position"])

    async def test_member_detail_returns_its_occurrences(self) -> None:
        org_id, project_id = await self.create_org_and_project()
        async with self.client() as client:
            member_res = await client.post(
                f"/api/orgs/{org_id}/members",
                json={"kind": "ai", "name": "Gemini", "participant_id": "gemini"},
            )
            member_id = member_res.json()["member"]["id"]
            await client.post(
                f"/api/projects/{project_id}/members", json={"org_member_id": member_id}
            )

            await client.post(
                f"/api/projects/{project_id}/messages",
                json={"content": "问 Gemini 一个问题", "target": "gemini"},
            )
            detail = (await client.get(f"/api/members/{member_id}")).json()
            # authored：Gemini 的 AI 回复；addressed：被 @ 指向的一条
            self.assertEqual(1, len(detail["authored"]))
            self.assertEqual("Gemini", detail["authored"][0]["author"])
            self.assertEqual(1, len(detail["addressed"]))
            self.assertEqual("Gemini", detail["addressed"][0]["target"])
            self.assertIsNone(detail["thread"])  # 假运行时无 thread 状态文件


if __name__ == "__main__":
    unittest.main()
