"""探究（question + 可再进入的多次尝试）测试。"""

from __future__ import annotations

import json
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


class AttemptEnvelopeRuntime:
    """返回带 working_report 信封的假运行时，并记录每次收到的输入。"""

    def __init__(self, prefix: str = "R") -> None:
        self.prefix = prefix
        self.received: list[str] = []
        self.counter = 0
        self.model = "deepseek-v4-flash"

    async def send(self, message: str) -> RuntimeReply:
        self.received.append(message)
        self.counter += 1
        envelope = {
            "orientation": {
                "status": "sufficient",
                "assumptions": [],
                "missing": [],
                "questions": [],
            },
            "working_report": {
                "touched": f"{self.prefix}-touched-{self.counter}",
                "changed": f"{self.prefix}-changed-{self.counter}",
                "still_open": f"{self.prefix}-open-{self.counter}",
                "next": f"{self.prefix}-next-{self.counter}",
            },
            "answer": f"{self.prefix}-answer-{self.counter}",
        }
        return RuntimeReply(
            thread_id=f"thread-{self.prefix}",
            turn_id=f"turn-{self.prefix}-{self.counter}",
            text=json.dumps(envelope, ensure_ascii=False),
            model_used="deepseek-v4-flash",
            requested_model="deepseek-v4-flash",
        )


class InquiryApiTest(unittest.IsolatedAsyncioTestCase):
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
        runtime = AttemptEnvelopeRuntime("R")
        self.participants = {
            "deepseek": Participant(
                id="deepseek",
                name="DeepSeek",
                model_name="DeepSeek V4 Flash",
                runtime=runtime,
            ),
        }
        self.runtime = runtime

    def tearDown(self) -> None:
        self.temporary_directory.cleanup()

    async def _setup_org_project(self, client) -> tuple[str, str]:
        org = (await client.post("/api/orgs", json={"name": "组织"})).json()["organization"]["id"]
        project = (await client.post(f"/api/orgs/{org}/projects", json={"title": "项目 X"})) .json()["project"]["id"]
        return org, project

    async def test_inquiry_create_and_attempts_chain(self) -> None:
        app = create_app(self.config, participants=self.participants)
        async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
            _, project_id = await self._setup_org_project(client)

            # 创建探究（问题本身成为一条带 inquiry_id 的发生）
            created = await client.post(
                f"/api/projects/{project_id}/inquiries",
                json={"question": "Cognos 怎么架构设计？"},
            )
            self.assertEqual(200, created.status_code)
            inquiry_id = created.json()["inquiry"]["id"]
            self.assertEqual(project_id, created.json()["inquiry"]["project_id"])
            self.assertTrue(created.json()["user_record"].get("inquiry_id"))

            # 第一次尝试
            attempt1 = await client.post(
                f"/api/inquiries/{inquiry_id}/attempts",
                json={"content": "先展开第一层", "target": "deepseek"},
            )
            self.assertEqual(200, attempt1.status_code)
            self.assertEqual(1, attempt1.json()["attempt"]["attempt_no"])
            reply1 = attempt1.json()["reply"]
            self.assertEqual(inquiry_id, reply1["inquiry_id"])
            self.assertEqual(1, reply1["attempt_no"])
            self.assertEqual("R-touched-1", reply1["working_report"]["touched"])
            self.assertEqual("R-answer-1", reply1["content"])

            # 第二次尝试（不带新指令，纯"再次尝试"）
            attempt2 = await client.post(
                f"/api/inquiries/{inquiry_id}/attempts",
                json={"content": "", "target": "deepseek"},
            )
            self.assertEqual(200, attempt2.status_code)
            self.assertEqual(2, attempt2.json()["attempt"]["attempt_no"])
            self.assertEqual("R-answer-2", attempt2.json()["reply"]["content"])

            # 第二次尝试的输入必须包含第一次的工作报告（承接，不从头重讲）
            second_input = self.runtime.received[-1]
            self.assertIn("当前探究：Cognos 怎么架构设计？", second_input)
            self.assertIn("R-touched-1", second_input)
            self.assertIn("R-open-1", second_input)
            self.assertIn("working_report", second_input)

            # 详情：attempts 列表 + 关联发生
            detail = (await client.get(f"/api/inquiries/{inquiry_id}")).json()
            self.assertEqual(2, len(detail["attempts"]))
            self.assertEqual("R-changed-2", detail["attempts"][1]["working_report"]["changed"])
            messages = detail["messages"]
            self.assertTrue(any(m.get("inquiry_question") for m in messages))
            self.assertEqual(4, len(messages))  # 问题 + 第一条用户指令 + 两次 AI 回复（无指令的第二次不产生用户记录）

            # 项目下的探究列表
            listed = (await client.get(f"/api/projects/{project_id}/inquiries")).json()
            self.assertEqual(1, len(listed["inquiries"]))
            self.assertEqual(2, listed["inquiries"][0]["attempt_count"])

    async def test_inquiry_404s(self) -> None:
        app = create_app(self.config, participants=self.participants)
        async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
            self.assertEqual(404, (await client.get("/api/inquiries/nope")).status_code)
            self.assertEqual(
                404,
                (await client.post("/api/inquiries/nope/attempts", json={"content": "x"})).status_code,
            )
            self.assertEqual(
                404,
                (await client.post("/api/projects/nope/inquiries", json={"question": "q"})).status_code,
            )

    async def test_attempt_stored_when_runtime_fails(self) -> None:
        """参与者不可用时，用户指令发生仍保存（与普通消息一致的纪律）。"""

        class FailingRuntime:
            model = "deepseek-v4-flash"

            async def send(self, message: str) -> RuntimeReply:
                from codex_runtime import RuntimeUnavailableError

                raise RuntimeUnavailableError("offline")

        participants = {
            "deepseek": Participant(
                id="deepseek",
                name="DeepSeek",
                model_name="DeepSeek V4 Flash",
                runtime=FailingRuntime(),
            ),
        }
        app = create_app(self.config, participants=participants)
        async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
            _, project_id = await self._setup_org_project(client)
            inquiry_id = (
                await client.post(
                    f"/api/projects/{project_id}/inquiries",
                    json={"question": "一个问题"},
                )
            ).json()["inquiry"]["id"]
            response = await client.post(
                f"/api/inquiries/{inquiry_id}/attempts",
                json={"content": "即使失败也留下", "target": "deepseek"},
            )
            self.assertEqual(502, response.status_code)
            detail = (await client.get(f"/api/inquiries/{inquiry_id}")).json()
            self.assertEqual([], detail["attempts"])
            self.assertTrue(
                any(m.get("content") == "即使失败也留下" for m in detail["messages"])
            )


if __name__ == "__main__":
    unittest.main()
