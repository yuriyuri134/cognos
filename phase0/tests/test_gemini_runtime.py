"""GeminiRuntimeAdapter 单元测试（离线 Mock 测试）。"""

import json
import os
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
            # 透明记录实际使用的模型
            self.assertEqual("gemini-3.7-flash", reply.model_used)

            # 验证状态持久化
            saved_state = self.store.load()
            self.assertIsNotNone(saved_state)
            self.assertEqual(len(saved_state.contents), 2)
            self.assertEqual(saved_state.contents[0]["parts"][0]["text"], "你好")
            self.assertEqual(saved_state.contents[1]["parts"][0]["text"], "你好！我是 Gemini。")

    async def test_fallback_disabled_uses_only_configured_model(self) -> None:
        """COGNOS_GEMINI_ALLOW_FALLBACK=0 时，只请求配置模型，错误直接暴露。"""

        not_found = httpx.Response(
            status_code=404,
            text="model not found",
            request=httpx.Request("POST", "http://test"),
        )
        mock_post = AsyncMock(return_value=not_found)

        with patch.dict(os.environ, {"COGNOS_GEMINI_ALLOW_FALLBACK": "0"}), patch(
            "httpx.AsyncClient.post", mock_post
        ):
            adapter = GeminiRuntimeAdapter(
                api_key="fake_key",
                state_store=self.store,
                model="gemini-3.7-flash",
            )
            with self.assertRaises(Exception) as ctx:
                await adapter.send("hi")

        # 只尝试了配置模型（未降级 3.6/3.5）；404 非 5xx/429，内层不重试
        called_models = mock_post.call_args_list
        urls = [c.args[0] for c in called_models]
        self.assertEqual(1, len(urls))
        for url in urls:
            self.assertIn("gemini-3.7-flash", url)
            self.assertNotIn("gemini-3.6", url)
            self.assertNotIn("gemini-3.5", url)
        self.assertIn("HTTP 404", str(ctx.exception))

    async def test_silent_fallback_records_model_used(self) -> None:
        """显式开启回退时（COGNOS_GEMINI_ALLOW_FALLBACK=1）降级，回复必须带实际模型。"""

        def fake_post(url: str, **kwargs):
            if "gemini-3.7-flash" in url:
                return httpx.Response(
                    404, text="no such model", request=httpx.Request("POST", "http://test")
                )
            return httpx.Response(
                200,
                text=json.dumps(
                    {"candidates": [{"content": {"parts": [{"text": "降级后的回复"}]}}]}
                ),
                request=httpx.Request("POST", "http://test"),
            )

        with patch.dict(os.environ, {"COGNOS_GEMINI_ALLOW_FALLBACK": "1"}), patch(
            "httpx.AsyncClient.post", AsyncMock(side_effect=fake_post)
        ):
            adapter = GeminiRuntimeAdapter(
                api_key="fake_key",
                state_store=self.store,
                model="gemini-3.7-flash",
            )
            reply = await adapter.send("hi")

        self.assertEqual("gemini-3.6-flash", reply.model_used)
        self.assertEqual("gemini-3.7-flash", reply.requested_model)

    async def test_strict_binding_is_default(self) -> None:
        """默认严格绑定：指定模型 404 时不降级，直接失败。"""

        not_found = httpx.Response(
            status_code=404,
            text="model not found",
            request=httpx.Request("POST", "http://test"),
        )
        mock_post = AsyncMock(return_value=not_found)
        with patch.dict(os.environ, {}, clear=False):
            os.environ.pop("COGNOS_GEMINI_ALLOW_FALLBACK", None)
            with patch("httpx.AsyncClient.post", mock_post):
                adapter = GeminiRuntimeAdapter(
                    api_key="fake_key",
                    state_store=self.store,
                    model="gemini-3.7-flash",
                )
                with self.assertRaises(Exception) as ctx:
                    await adapter.send("hi")
        urls = [c.args[0] for c in mock_post.call_args_list]
        for url in urls:
            self.assertIn("gemini-3.7-flash", url)
            self.assertNotIn("gemini-3.6", url)
        self.assertIn("HTTP 404", str(ctx.exception))

    async def test_search_grounding_sends_tool_and_parses_sources(self) -> None:
        fake_response = {
            "candidates": [
                {
                    "content": {"parts": [{"text": "据检索来源。"}], "role": "model"},
                    "groundingMetadata": {
                        "groundingChunks": [
                            {"web": {"uri": "https://example.com/a", "title": "来源 A"}},
                            {"web": {"uri": "https://example.com/b", "title": "来源 B"}},
                        ],
                        "groundingSupports": [],
                    },
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
                api_key="fake_key", state_store=self.store, search=True
            )
            reply = await adapter.send("查一下")

        self.assertEqual(2, len(reply.sources))
        self.assertEqual("https://example.com/a", reply.sources[0]["uri"])
        self.assertEqual("来源 A", reply.sources[0]["title"])
        body = mock_post.call_args.kwargs["json"]
        self.assertEqual([{"google_search": {}}], body["tools"])

    async def test_without_search_no_tools_in_payload(self) -> None:
        success = httpx.Response(
            status_code=200,
            text=json.dumps(
                {"candidates": [{"content": {"parts": [{"text": "hi"}], "role": "model"}}]}
            ),
            request=httpx.Request("POST", "http://test"),
        )
        mock_post = AsyncMock(return_value=success)
        with patch("httpx.AsyncClient.post", mock_post):
            adapter = GeminiRuntimeAdapter(
                api_key="fake_key", state_store=self.store, model="gemini-3.7-flash"
            )
            await adapter.send("你好")
        body = mock_post.call_args.kwargs["json"]
        self.assertNotIn("tools", body)


if __name__ == "__main__":
    unittest.main()
