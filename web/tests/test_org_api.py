"""Organization v0 API 测试；用假运行时，不访问网络。

覆盖：组织/项目/成员 CRUD、项目内消息作用域、跨项目引用、AI 成员校验。
"""

from __future__ import annotations

import sys
import tempfile
import unittest
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

    async def client(self):
        return AsyncClient(transport=ASGITransport(app=self.app), base_url="http://test")

    async def create_org_and_project(self):
        async with await self.client() as client:
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
        async with await self.client() as client:
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
            self.assertEqual(2, len(org_detail["members"]))

            # 项目消息视图返回成员
            messages = (await client.get(f"/api/projects/{project_id}/messages")).json()
            self.assertEqual(2, len(messages["members"]))
            self.assertEqual("Gemini", messages["members"][1]["name"])
            self.assertEqual("ai", messages["members"][1]["kind"])

    async def test_project_messages_are_scoped_but_global_field_keeps_all(self) -> None:
        org_id, project_id = await self.create_org_and_project()
        async with await self.client() as client:
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

            # 全局发生场仍然包含所有 raw 发生（第一发生场 = raw 全量）
            global_field = (await client.get("/api/bootstrap")).json()
            self.assertEqual(2, len(global_field["messages"]))
            self.assertTrue(
                all(m.get("project_id") == project_id for m in global_field["messages"])
            )

    async def test_two_projects_are_isolated(self) -> None:
        org_id, project_a = await self.create_org_and_project()
        async with await self.client() as client:
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
        async with await self.client() as client:
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
        async with await self.client() as client:
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

    async def test_unknown_org_project_return_404(self) -> None:
        async with await self.client() as client:
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
        async with await self.client() as client:
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


if __name__ == "__main__":
    unittest.main()
