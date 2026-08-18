"""GeminiRuntimeAdapter 单元测试（离线 Mock 测试）。"""

import json
import tempfile
import unittest
from pathlib import Path
from unittest.mock import AsyncMock, patch

import httpx

from gemini_runtime import (
    GeminiConversationState,
    GeminiRuntimeAdapter,
    GeminiStateStore,
)


class GeminiRuntimeAdapterTest(unittest.IsolatedAsyncioTestCase):
    def setUp(self) -> None:
        self.tmp_dir = tempfile.TemporaryDirectory()
        self.state_file = Path(self.tmp_dir.name) / "gemini_thread.json"
        self.store = GeminiStateStore(self.state_file)

    def tearDown(self) -> None:
        self.tmp_dir.cleanup()

    def test_state_store_save_and_load(self) -> None:
        state = GeminiConversationState(
            thread_id="test_thread_1",
            contents=[{"role": "user", "parts": [{"text": "hello"}]}],
        )
        self.store.save(state)
        loaded = self.store.load()
        self.assertIsNotNone(loaded)
        self.assertEqual(loaded.thread_id, "test_thread_1")
        self.assertEqual(len(loaded.contents), 1)

    async def test_adapter_send_success(self) -> None:
        fake_response = {
            "candidates": [
                {
                    "content": {
                        "parts": [{"text": "你好！我是 Gemini。"}],
                        "role": "model",
                    }
                }
            ]
        }

        mock_post = AsyncMock(
            return_value=httpx.Response(
                status_code=200,
                text=json.dumps(fake_response),
                request=httpx.Request("POST", "http://test"),
            )
        )

        with patch("httpx.AsyncClient.post", mock_post):
            adapter = GeminiRuntimeAdapter(
                api_key="fake_key",
                state_store=self.store,
                model="gemini-3.7-flash",
                developer_instructions="Be concise.",
            )
            reply = await adapter.send("你好")

            self.assertTrue(reply.thread_id.startswith("gemini_"))
            self.assertTrue(reply.turn_id.startswith("turn_"))
            self.assertEqual(reply.text, "你好！我是 Gemini。")

            # 验证状态持久化
            saved_state = self.store.load()
            self.assertIsNotNone(saved_state)
            self.assertEqual(len(saved_state.contents), 2)
            self.assertEqual(saved_state.contents[0]["parts"][0]["text"], "你好")
            self.assertEqual(saved_state.contents[1]["parts"][0]["text"], "你好！我是 Gemini。")


if __name__ == "__main__":
    unittest.main()
