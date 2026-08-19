"""网页接口竖切测试；用假运行时，不访问网络。"""

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

from codex_runtime import RuntimeReply, RuntimeUnavailableError
from server import AppConfig, Participant, create_app


class FakeRuntime:
    def __init__(self, prefix: str = "fake", thread_id: str = "thread-web") -> None:
        self.prefix = prefix
        self.thread_id = thread_id

    async def send(self, message: str) -> RuntimeReply:
        return RuntimeReply(
            thread_id=self.thread_id,
            turn_id=f"turn-{self.prefix}-fixed",
            text=f"{self.prefix} 回复：{message}",
        )


class FailingRuntime:
    async def send(self, message: str) -> RuntimeReply:
        raise RuntimeUnavailableError("offline")


class WebSliceTest(unittest.IsolatedAsyncioTestCase):
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

    async def test_page_and_health_are_available(self) -> None:
        app = create_app(self.config, runtime=FakeRuntime())
        async with AsyncClient(
            transport=ASGITransport(app=app), base_url="http://test"
        ) as client:
            page = await client.get("/")
            health = await client.get("/api/health")

        self.assertEqual(200, page.status_code)
        self.assertIn("第一发生场", page.text)
        self.assertEqual({"status": "ready"}, health.json())

    async def test_message_round_trip_is_persisted_across_app_recreation(self) -> None:
        first_app = create_app(self.config, runtime=FakeRuntime())
        async with AsyncClient(
            transport=ASGITransport(app=first_app), base_url="http://test"
        ) as client:
            response = await client.post("/api/messages", json={"content": "从这里继续"})
            self.assertEqual(200, response.status_code)
            self.assertEqual("thread-web", response.json()["state"]["thread_id"])

        reopened_app = create_app(self.config, runtime=FakeRuntime())
        async with AsyncClient(
            transport=ASGITransport(app=reopened_app), base_url="http://test"
        ) as reopened:
            payload = (await reopened.get("/api/bootstrap")).json()

        self.assertEqual(2, len(payload["messages"]))
        self.assertEqual(["小林", "Codex"], [item["author"] for item in payload["messages"]])
        self.assertEqual("从这里继续", payload["messages"][0]["content"])
        self.assertEqual("turn-fake-fixed", payload["state"]["latest_runtime_ref"]["turn_id"])

    async def test_multi_participant_coexistence_and_routing(self) -> None:
        participants = {
            "gemini": Participant(
                id="gemini",
                name="Gemini",
                model_name="Gemini 3.7 Flash",
                runtime=FakeRuntime(prefix="G", thread_id="thread-gemini-1"),
            ),
            "codex": Participant(
                id="codex",
                name="Codex",
                model_name="Codex App-Server",
                runtime=FakeRuntime(prefix="C", thread_id="thread-codex-1"),
            ),
        }
        app = create_app(self.config, participants=participants)
        async with AsyncClient(
            transport=ASGITransport(app=app), base_url="http://test"
        ) as client:
            # 1. 小林 -> @Gemini
            res1 = await client.post("/api/messages", json={"content": "@Gemini 第一条消息"})
            self.assertEqual(200, res1.status_code)
            self.assertEqual("Gemini", res1.json()["reply"]["author"])
            self.assertEqual("thread-gemini-1", res1.json()["state"]["thread_id"])

            # 2. 小林 -> @Codex
            res2 = await client.post("/api/messages", json={"content": "@Codex 你看一下 Gemini 的发言"})
            self.assertEqual(200, res2.status_code)
            self.assertEqual("Codex", res2.json()["reply"]["author"])
            self.assertEqual("thread-codex-1", res2.json()["state"]["thread_id"])

            # 3. 再次呼叫 @Gemini，验证 Gemini 的连续性 thread-gemini-1 不受影响
            res3 = await client.post("/api/messages", json={"content": "@Gemini 回到你的连续性"})
            self.assertEqual(200, res3.status_code)
            self.assertEqual("Gemini", res3.json()["reply"]["author"])
            self.assertEqual("thread-gemini-1", res3.json()["state"]["thread_id"])

            # 验证全量历史中有 6 条记录（3条小林 + 2条Gemini + 1条Codex）
            boot = (await client.get("/api/bootstrap")).json()
            self.assertEqual(6, len(boot["messages"]))
            authors = [m["author"] for m in boot["messages"]]
            self.assertEqual(["小林", "Gemini", "小林", "Codex", "小林", "Gemini"], authors)
            self.assertEqual(2, len(boot["participants"]))

    async def test_quoted_occurrence_presentation_across_participants(self) -> None:
        last_received: dict[str, str] = {}

        class RecordingFakeRuntime:
            def __init__(self, prefix: str) -> None:
                self.prefix = prefix
            async def send(self, message: str) -> RuntimeReply:
                last_received[self.prefix] = message
                return RuntimeReply(
                    thread_id=f"thread-{self.prefix}",
                    turn_id=f"turn-{self.prefix}-1",
                    text=f"已阅：{message[:20]}",
                )

        participants = {
            "gemini": Participant(
                id="gemini",
                name="Gemini",
                model_name="Gemini 3.7",
                runtime=RecordingFakeRuntime("G"),
            ),
            "codex": Participant(
                id="codex",
                name="Codex",
                model_name="Codex App-Server",
                runtime=RecordingFakeRuntime("C"),
            ),
        }
        app = create_app(self.config, participants=participants)
        async with AsyncClient(
            transport=ASGITransport(app=app), base_url="http://test"
        ) as client:
            # 1. Gemini 发言产生发生 A
            res1 = await client.post("/api/messages", json={"content": "这是初始判断", "target": "gemini"})
            gemini_reply_id = res1.json()["reply"]["id"]

            # 2. 小林引用 A 并发送给 Codex
            res2 = await client.post(
                "/api/messages",
                json={
                    "content": "结合代码你看如何？",
                    "target": "codex",
                    "quoted_occurrence_id": gemini_reply_id,
                },
            )
            self.assertEqual(200, res2.status_code)
            self.assertIn("quoted_occurrence_id", res2.json()["user"])
            self.assertEqual(gemini_reply_id, res2.json()["user"]["quoted_occurrence_id"])

            # 验证 Codex 收到的输入包含格式化的发生呈现
            codex_input = last_received["C"]
            self.assertIn("[来自当前发生场的已有发生]", codex_input)
            self.assertIn(f"occurrence_id: {gemini_reply_id}", codex_input)
            self.assertIn("author: Gemini", codex_input)
            self.assertIn("结合代码你看如何？", codex_input)

    async def test_failed_runtime_keeps_original_user_occurrence(self) -> None:
        app = create_app(self.config, runtime=FailingRuntime())
        async with AsyncClient(
            transport=ASGITransport(app=app), base_url="http://test"
        ) as client:
            response = await client.post(
                "/api/messages", json={"content": "即使失败也不要抹掉"}
            )
            history = (await client.get("/api/bootstrap")).json()["messages"]

        self.assertEqual(502, response.status_code)
        self.assertEqual(1, len(history))
        self.assertEqual("即使失败也不要抹掉", history[0]["content"])

    async def test_blank_message_is_rejected_without_history(self) -> None:
        app = create_app(self.config, runtime=FakeRuntime())
        async with AsyncClient(
            transport=ASGITransport(app=app), base_url="http://test"
        ) as client:
            response = await client.post("/api/messages", json={"content": "   \n"})
            history = (await client.get("/api/bootstrap")).json()["messages"]

        self.assertEqual(422, response.status_code)
        self.assertEqual([], history)


if __name__ == "__main__":
    unittest.main()
