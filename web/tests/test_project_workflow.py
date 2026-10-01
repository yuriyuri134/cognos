"""Slice 1 工作闭环测试：项目频道 → 决定 → 工作项 → 交接 → 结果 → 时间线。

全部用假运行时，不访问网络；验证的是普通软件对象与发生层之间的连接，
不把这些对象升级为 Space / Attempt 等本体。
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
    def __init__(self, prefix: str = "fake", thread_id: str = "thread-workflow") -> None:
        self.prefix = prefix
        self.thread_id = thread_id
        self.inputs: list[str] = []

    async def send(self, message: str) -> RuntimeReply:
        self.inputs.append(message)
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
            runtime=FakeRuntime(prefix="G", thread_id="thread-gemini-workflow"),
        ),
        "codex": Participant(
            id="codex",
            name="Codex",
            model_name="Codex App-Server",
            runtime=FakeRuntime(prefix="C", thread_id="thread-codex-workflow"),
        ),
    }


class ProjectWorkflowTest(unittest.IsolatedAsyncioTestCase):
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

    async def create_org_project_member(self):
        async with await self.client() as client:
            org_id = (
                await client.post("/api/orgs", json={"name": "Cognos 组织"})
            ).json()["organization"]["id"]
            project_id = (
                await client.post(
                    f"/api/orgs/{org_id}/projects", json={"title": "第一个真实项目"}
                )
            ).json()["project"]["id"]
            member_id = (
                await client.post(
                    f"/api/orgs/{org_id}/members",
                    json={"kind": "ai", "name": "Gemini", "participant_id": "gemini"},
                )
            ).json()["member"]["id"]
            await client.post(
                f"/api/projects/{project_id}/members", json={"org_member_id": member_id}
            )
            return org_id, project_id, member_id

    async def test_default_channel_projects_old_and_new_messages(self) -> None:
        _, project_id, _ = await self.create_org_project_member()
        async with await self.client() as client:
            conversations = (
                await client.get(f"/api/projects/{project_id}/conversations")
            ).json()
            default_id = conversations["default_conversation_id"]
            self.assertTrue(default_id)
            self.assertEqual(1, len(conversations["conversations"]))
            self.assertEqual(1, conversations["conversations"][0]["is_default"])

            # 新消息通过项目路径发出，应该带上默认频道
            sent = await client.post(
                f"/api/projects/{project_id}/messages",
                json={"content": "从项目频道发出的讨论", "target": "gemini"},
            )
            self.assertEqual(200, sent.status_code)
            self.assertEqual(default_id, sent.json()["user"]["conversation_id"])
            self.assertEqual(default_id, sent.json()["reply"]["conversation_id"])

            # 手工追加一条没有 conversation_id 的旧 occurrence（模拟历史数据）
            self.app.state.history.append_message(
                author="小林",
                content="没有频道的旧发生",
                project_id=project_id,
            )

            # 默认频道按 project_id 投影旧 occurrence，不回写原文
            messages = (
                await client.get(f"/api/conversations/{default_id}/messages")
            ).json()["messages"]
            self.assertEqual(3, len(messages))
            self.assertTrue(all(
                item.get("conversation_id") == default_id or item.get("project_id") == project_id
                for item in messages
            ))

    async def test_decision_from_message_keeps_source_refs(self) -> None:
        _, project_id, _ = await self.create_org_project_member()
        async with await self.client() as client:
            sent = await client.post(
                f"/api/projects/{project_id}/messages",
                json={"content": "先把项目频道做出来", "target": "gemini"},
            )
            message_id = sent.json()["user"]["id"]

            created = await client.post(
                f"/api/projects/{project_id}/decisions",
                json={
                    "content": "先完成 Slice 1：频道 + 决定 + 工作项 + 时间线。",
                    "source_refs": [message_id],
                    "reopen_condition": "如果闭环跑不通，重开讨论。",
                },
            )
            self.assertEqual(200, created.status_code)
            decision = created.json()["decision"]
            self.assertEqual("accepted", decision["status"])
            self.assertEqual([message_id], decision["source_refs"])
            self.assertEqual("小林", decision["decided_by"])

            listed = (await client.get(f"/api/projects/{project_id}/decisions")).json()
            self.assertEqual(1, len(listed["decisions"]))

    async def test_work_item_lifecycle_and_events(self) -> None:
        _, project_id, member_id = await self.create_org_project_member()
        async with await self.client() as client:
            decision_id = (
                await client.post(
                    f"/api/projects/{project_id}/decisions",
                    json={"content": "实现项目时间线。"},
                )
            ).json()["decision"]["id"]

            created = await client.post(
                f"/api/projects/{project_id}/work-items",
                json={
                    "title": "实现 timeline 只读聚合",
                    "description": "在 project_api 中合并发生、决定与工作项事件。",
                    "assignee_member_id": member_id,
                    "decision_id": decision_id,
                },
            )
            self.assertEqual(200, created.status_code)
            item = created.json()["work_item"]
            self.assertEqual("open", item["status"])
            self.assertEqual(decision_id, item["decision_id"])
            self.assertEqual(member_id, item["assignee_member_id"])

            updated = await client.patch(
                f"/api/work-items/{item['id']}", json={"status": "in_progress"}
            )
            self.assertEqual("in_progress", updated.json()["work_item"]["status"])

            detail = (await client.get(f"/api/work-items/{item['id']}")).json()
            kinds = [event["kind"] for event in detail["events"]]
            self.assertIn("created", kinds)
            self.assertIn("status", kinds)

            listed = (
                await client.get(f"/api/projects/{project_id}/work-items")
            ).json()["work_items"]
            self.assertEqual(1, len(listed))

    async def test_work_item_handoff_and_result_round_trip(self) -> None:
        _, project_id, member_id = await self.create_org_project_member()
        async with await self.client() as client:
            message_id = (
                await client.post(
                    f"/api/projects/{project_id}/messages",
                    json={"content": "这次改代码需要有真实测试证据。", "target": "gemini"},
                )
            ).json()["user"]["id"]
            item_id = (
                await client.post(
                    f"/api/projects/{project_id}/work-items",
                    json={
                        "title": "给项目频道补一个接口",
                        "description": "补上时间线接口并跑测试。",
                        "source_refs": [message_id],
                    },
                )
            ).json()["work_item"]["id"]

            handoff = await client.post(
                "/api/handoff/create",
                json={
                    "instruction": "执行工作项：给项目频道补一个接口",
                    "target_agent": "DSH",
                    "project_id": project_id,
                    "work_item_id": item_id,
                },
            )
            self.assertEqual(200, handoff.status_code)
            handoff_record = handoff.json()["record"]
            self.assertEqual(project_id, handoff_record["project_id"])
            self.assertEqual(item_id, handoff_record["work_item_id"])
            self.assertEqual(item_id, handoff_record["handoff_request"]["work_item_id"])

            # 交接后工作项事件里能看到 handoff，且未开始的工作项进入 in_progress
            detail = (await client.get(f"/api/work-items/{item_id}")).json()
            self.assertEqual("in_progress", detail["work_item"]["status"])
            self.assertIn("handoff", [event["kind"] for event in detail["events"]])

            result = await client.post(
                "/api/handoff/result",
                json={
                    "handoff_id": handoff_record["id"],
                    "agent_name": "DSH",
                    "agent_response": "已实现并跑通测试。",
                    "changed_files": ["web/project_api.py"],
                    "commands_run": ["pytest -q"],
                    "test_provenance": {"status": "passed", "test_runs": []},
                },
            )
            self.assertEqual(200, result.status_code)
            result_record = result.json()["record"]
            self.assertEqual(project_id, result_record["project_id"])
            self.assertEqual(item_id, result_record["work_item_id"])

            detail_after = (await client.get(f"/api/work-items/{item_id}")).json()
            self.assertIn("result", [event["kind"] for event in detail_after["events"]])

    async def test_timeline_merges_all_slice1_objects(self) -> None:
        _, project_id, _ = await self.create_org_project_member()
        async with await self.client() as client:
            await client.post(
                f"/api/projects/{project_id}/messages",
                json={"content": "先在项目频道讨论。", "target": "gemini"},
            )
            decision_id = (
                await client.post(
                    f"/api/projects/{project_id}/decisions",
                    json={"content": "决定：先做闭环。"},
                )
            ).json()["decision"]["id"]
            item_id = (
                await client.post(
                    f"/api/projects/{project_id}/work-items",
                    json={"title": "闭合一次工作", "decision_id": decision_id},
                )
            ).json()["work_item"]["id"]
            handoff_id = (
                await client.post(
                    "/api/handoff/create",
                    json={
                        "instruction": "执行闭环验证",
                        "target_agent": "DSH",
                        "project_id": project_id,
                        "work_item_id": item_id,
                    },
                )
            ).json()["record"]["id"]
            await client.post(
                "/api/handoff/result",
                json={
                    "handoff_id": handoff_id,
                    "agent_name": "DSH",
                    "agent_response": "闭环验证完成。",
                },
            )

            timeline = (await client.get(f"/api/projects/{project_id}/timeline")).json()
            kinds = {event["kind"] for event in timeline["events"]}
            self.assertIn("message", kinds)
            self.assertIn("decision", kinds)
            self.assertIn("work_item", kinds)
            self.assertIn("handoff", kinds)
            self.assertIn("result", kinds)
            # 时间线按时间倒序
            ats = [event.get("at") for event in timeline["events"]]
            self.assertEqual(ats, sorted(ats, reverse=True))

    async def test_conversation_message_send_and_unknown_ids(self) -> None:
        _, project_id, _ = await self.create_org_project_member()
        async with await self.client() as client:
            default_id = (
                await client.get(f"/api/projects/{project_id}/conversations")
            ).json()["default_conversation_id"]

            sent = await client.post(
                f"/api/conversations/{default_id}/messages",
                json={"content": "在默认频道里说话", "target": "codex"},
            )
            self.assertEqual(200, sent.status_code)
            self.assertEqual(project_id, sent.json()["user"]["project_id"])
            self.assertEqual(default_id, sent.json()["user"]["conversation_id"])

            self.assertEqual(
                404, (await client.get("/api/conversations/nope/messages")).status_code
            )
            self.assertEqual(
                404,
                (
                    await client.post(
                        "/api/conversations/nope/messages", json={"content": "x"}
                    )
                ).status_code,
            )
            self.assertEqual(
                404, (await client.get(f"/api/work-items/nope")).status_code
            )

    async def test_recent_occurrences_enter_situation(self) -> None:
        """触发式能力：场内最近发生要进入处境，否则参与者“东西都在但接不上”。"""

        _, project_id, _ = await self.create_org_project_member()
        async with await self.client() as client:
            await client.post(
                f"/api/projects/{project_id}/messages",
                json={"content": "先讨论赚钱的两种尺度：现金流和长期结构", "target": "gemini"},
            )
            await client.post(
                f"/api/projects/{project_id}/messages",
                json={"content": "怎么赚钱？", "target": "gemini"},
            )

        runtime = self.app.state.participants["gemini"].runtime
        last_prompt = runtime.inputs[-1]
        self.assertIn("场内最近发生", last_prompt)
        self.assertIn("先讨论赚钱的两种尺度", last_prompt)
        # 引用式暴露：带 occurrence_id，可被后续引用，不是模型自由总结
        self.assertIn("occurrence_id=", last_prompt)

    async def test_workbench_frontend_assets_are_served(self) -> None:
        async with await self.client() as client:
            index = await client.get("/")
            self.assertEqual(200, index.status_code)
            self.assertIn("/assets/workbench.js", index.text)
            self.assertIn('id="project-page"', index.text)
            self.assertIn('id="project-tabs"', index.text)
            self.assertIn('data-tab="work_items"', index.text)

            script = await client.get("/assets/workbench.js")
            self.assertEqual(200, script.status_code)
            self.assertIn("项目工作台", script.text)

            style = await client.get("/assets/workbench.css")
            self.assertEqual(200, style.status_code)


if __name__ == "__main__":
    unittest.main()
