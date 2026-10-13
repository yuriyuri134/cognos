"""Situated Cognition v0 测试：处境投影、orientation gate、situation provenance。

施工名；不宣称实现 Space / Learning 本体。
"""

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
from situation import (
    build_situation,
    build_situation_prompt,
    format_questions,
    load_situation,
    parse_orientation,
    save_situation,
)


class SituationBuildTest(unittest.TestCase):
    def _situation(self) -> dict:
        return build_situation(
            participant={"id": "deepseek", "name": "DeepSeek", "model_name": "DeepSeek V4 Flash", "runtime": "deepseek"},
            requested_model="deepseek-v4-flash",
            locus={
                "type": "project",
                "organization": {"id": "o1", "name": "组织 A"},
                "project": {"id": "p1", "title": "真实项目", "description": "推进某问题", "status": "active"},
            },
            content="把这条改一下",
            quoted=[{"id": "occ-1", "author": "Gemini", "content": "原判断"}],
            project_members=[
                {"name": "DeepSeek", "kind": "ai", "org_role": "施工主体", "position": "执行衔接"},
                {"name": "小林", "kind": "human", "org_role": "", "position": ""},
            ],
            frame_sources=[{"path": "10 Projects/Cognos/定位与边界.md", "content": "# 定位与边界\n软件是第一数字身体。"}],
        )

    def test_hash_deterministic_for_same_content(self) -> None:
        first = self._situation()
        second = self._situation()
        self.assertNotEqual(first["situation_id"], second["situation_id"])
        self.assertEqual(first["situation_hash"], second["situation_hash"])

    def test_prompt_contains_locus_matter_frame_and_orientation_advisory(self) -> None:
        prompt = build_situation_prompt(self._situation())
        self.assertIn("DeepSeek", prompt)
        self.assertIn("deepseek-v4-flash", prompt)
        self.assertIn("组织「组织 A」→ 项目「真实项目」", prompt)
        self.assertIn("推进某问题", prompt)
        self.assertIn("施工主体", prompt)
        self.assertIn("执行衔接", prompt)
        self.assertIn("[来自当前发生场的已有发生]", prompt)
        self.assertIn("occurrence_id: occ-1", prompt)
        self.assertIn("author: Gemini", prompt)
        self.assertIn("当前在说：", prompt)
        self.assertIn("把这条改一下", prompt)
        self.assertIn("定位与边界.md", prompt)
        self.assertIn("软件是第一数字身体。", prompt)
        self.assertIn("clarification_required", prompt)
        self.assertIn("orientation advisory", prompt)


class ParseOrientationTest(unittest.TestCase):
    def test_valid_envelope(self) -> None:
        text = '{"orientation":{"status":"sufficient","assumptions":[],"missing":[],"questions":[]},"answer":"可以。"}'
        orientation, answer, _report = parse_orientation(text)
        self.assertEqual("sufficient", orientation["status"])
        self.assertEqual("可以。", answer)

    def test_fenced_json(self) -> None:
        text = '```json\n{"orientation":{"status":"assumption","assumptions":["x=y"]},"answer":"按假设作答"}\n```'
        orientation, answer, _report = parse_orientation(text)
        self.assertEqual("assumption", orientation["status"])
        self.assertEqual("按假设作答", answer)

    def test_garbage_degrades_to_plain_answer(self) -> None:
        raw = "我不知道格式，直接说：好的。"
        orientation, answer, _report = parse_orientation(raw)
        self.assertIsNone(orientation)
        self.assertEqual(raw, answer)

    def test_clarification_questions_formatting(self) -> None:
        orientation = {"status": "clarification_required", "missing": ["目标"], "questions": ["你要改哪一条？"]}
        formatted = format_questions(orientation)
        self.assertIn("[需要澄清", formatted)
        self.assertIn("缺：目标", formatted)
        self.assertIn("问：你要改哪一条？", formatted)


class EnvelopeRuntime:
    """模拟返回 orientation 信封的运行时。"""

    def __init__(self, envelope: dict | None, model_used: str = "deepseek-v4-flash") -> None:
        self.envelope = envelope
        self.model_used = model_used
        self.model = "deepseek-v4-flash"

    async def send(self, message: str) -> RuntimeReply:
        if self.envelope is not None:
            text = json.dumps(self.envelope, ensure_ascii=False)
        else:
            text = "普通回复文本"
        return RuntimeReply(
            thread_id="thread-env",
            turn_id="turn-env",
            text=text,
            model_used=self.model_used,
            requested_model="deepseek-v4-flash",
        )


class SituationServiceTest(unittest.IsolatedAsyncioTestCase):
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

    def tearDown(self) -> None:
        self.temporary_directory.cleanup()

    async def _client(self, participants: dict[str, Participant]):
        from contextlib import asynccontextmanager
        from test_helpers import login_test_user
        app = create_app(self.config, participants=participants)
        @asynccontextmanager
        async def authenticated_client():
            async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
                await login_test_user(client)
                yield client
        return authenticated_client(), app

    async def test_provenance_recorded_and_situation_fetchable(self) -> None:
        participants = {
            "deepseek": Participant(
                id="deepseek",
                name="DeepSeek",
                model_name="DeepSeek V4 Flash",
                runtime=EnvelopeRuntime(None),
            ),
        }
        client, app = await self._client(participants)
        async with client as client:
            response = await client.post("/api/messages", json={"content": "你好", "target": "deepseek"})
            self.assertEqual(200, response.status_code)
            reply = response.json()["reply"]
            self.assertTrue(reply.get("situation_ref"))
            self.assertTrue(reply.get("situation_hash"))
            self.assertEqual("deepseek-v4-flash", reply.get("requested_model"))
            self.assertEqual("deepseek-v4-flash", reply.get("actual_model"))

            situation_id = reply["situation_ref"]
            situation = (await client.get(f"/api/situations/{situation_id}")).json()
            self.assertEqual("deepseek", situation["participant"]["id"])
            self.assertEqual("你好", situation["current_matter"]["content"])
            self.assertEqual("field", situation["locus"]["type"])

            # 投影文件确实落盘（server 端 fallback 到 thread_state_dir/situations）
            saved = load_situation(self.config.thread_state_dir / "situations", situation_id)
            self.assertIsNotNone(saved)

    async def test_clarification_gate_blocks_full_answer(self) -> None:
        envelope = {
            "orientation": {
                "status": "clarification_required",
                "assumptions": [],
                "missing": ["目标不明确"],
                "questions": ["你想改动哪一部分？"],
            },
            "answer": "",
        }
        participants = {
            "deepseek": Participant(
                id="deepseek",
                name="DeepSeek",
                model_name="DeepSeek V4 Flash",
                runtime=EnvelopeRuntime(envelope),
            ),
        }
        client, _ = await self._client(participants)
        async with client as client:
            response = await client.post("/api/messages", json={"content": "帮我改一下", "target": "deepseek"})
        reply = response.json()["reply"]
        self.assertIn("[需要澄清", reply["content"])
        self.assertIn("你想改动哪一部分？", reply["content"])
        self.assertEqual("clarification_required", reply["orientation"]["status"])

    async def test_clarification_status_can_keep_tentative_answer(self) -> None:
        envelope = {
            "orientation": {
                "status": "clarification_required",
                "assumptions": [],
                "missing": ["目标不明确"],
                "questions": ["你想改动哪一部分？"],
            },
            "answer": "我先暂定你是在讨论活动入口；如果不是，请补充目标。",
        }
        participants = {
            "deepseek": Participant(
                id="deepseek",
                name="DeepSeek",
                model_name="DeepSeek V4 Flash",
                runtime=EnvelopeRuntime(envelope),
            ),
        }
        client, _ = await self._client(participants)
        async with client as client:
            response = await client.post("/api/messages", json={"content": "帮我改一下", "target": "deepseek"})
        reply = response.json()["reply"]
        self.assertEqual("我先暂定你是在讨论活动入口；如果不是，请补充目标。", reply["content"])
        self.assertEqual("clarification_required", reply["orientation"]["status"])

    async def test_assumption_envelope_keeps_answer_and_marks(self) -> None:
        envelope = {
            "orientation": {
                "status": "assumption",
                "assumptions": ["假设你指的是 web 前端"],
                "missing": [],
                "questions": [],
            },
            "answer": "按假设回答。",
        }
        participants = {
            "deepseek": Participant(
                id="deepseek",
                name="DeepSeek",
                model_name="DeepSeek V4 Flash",
                runtime=EnvelopeRuntime(envelope),
            ),
        }
        client, _ = await self._client(participants)
        async with client as client:
            response = await client.post("/api/messages", json={"content": "改一下", "target": "deepseek"})
        reply = response.json()["reply"]
        self.assertEqual("按假设回答。", reply["content"])
        self.assertEqual("assumption", reply["orientation"]["status"])

    async def test_project_locus_enters_situation(self) -> None:
        participants = {
            "deepseek": Participant(
                id="deepseek",
                name="DeepSeek",
                model_name="DeepSeek V4 Flash",
                runtime=EnvelopeRuntime(None),
            ),
        }
        client, app = await self._client(participants)
        async with client as client:
            org_id = (await client.post("/api/orgs", json={"name": "组织"})) .json()["organization"]["id"]
            project_id = (await client.post(f"/api/orgs/{org_id}/projects", json={"title": "项目 X", "description": "推进真实问题"})) .json()["project"]["id"]
            member = (await client.post(f"/api/orgs/{org_id}/members", json={"kind": "ai", "name": "DeepSeek", "participant_id": "deepseek", "org_role": "施工主体"})) .json()["member"]["id"]
            await client.post(f"/api/projects/{project_id}/members", json={"org_member_id": member})

            response = await client.post(
                f"/api/projects/{project_id}/messages",
                json={"content": "在项目里说", "target": "deepseek"},
            )
            situation_id = response.json()["reply"]["situation_ref"]
            situation = (await client.get(f"/api/situations/{situation_id}")).json()
        self.assertEqual("project", situation["locus"]["type"])
        self.assertEqual("项目 X", situation["locus"]["project"]["title"])
        self.assertEqual("组织", situation["locus"]["organization"]["name"])
        self.assertEqual(2, len(situation["project_reality"]["members"]))
        self.assertEqual({"小林", "DeepSeek"}, {m["name"] for m in situation["project_reality"]["members"]})


if __name__ == "__main__":
    unittest.main()
