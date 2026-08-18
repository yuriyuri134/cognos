"""DeepSeekRuntimeAdapter 单元测试（离线 Mock 测试，不访问网络）。"""

from __future__ import annotations

import json
import tempfile
import unittest
from pathlib import Path
from unittest.mock import AsyncMock, patch

import httpx

from deepseek_runtime import (
    DeepSeekConversationState,
    DeepSeekRuntimeAdapter,
    DeepSeekStateStore,
    _read_credentials_yaml,
    load_deepseek_config,
)


class DeepSeekStateStoreTest(unittest.TestCase):
    def setUp(self) -> None:
        self.tmp_dir = tempfile.TemporaryDirectory()
        self.state_file = Path(self.tmp_dir.name) / "deepseek_thread.json"

    def tearDown(self) -> None:
        self.tmp_dir.cleanup()

    def test_state_store_save_and_load(self) -> None:
        store = DeepSeekStateStore(self.state_file)
        state = DeepSeekConversationState(
            thread_id="deepseek_1",
            contents=[{"role": "user", "content": "hello"}],
        )
        store.save(state)
        loaded = store.load()
        self.assertIsNotNone(loaded)
        self.assertEqual("deepseek_1", loaded.thread_id)
        self.assertEqual(1, len(loaded.contents))

    def test_load_returns_none_when_missing(self) -> None:
        store = DeepSeekStateStore(self.state_file)
        self.assertIsNone(store.load())


class DeepSeekRuntimeAdapterTest(unittest.IsolatedAsyncioTestCase):
    def setUp(self) -> None:
        self.tmp_dir = tempfile.TemporaryDirectory()
        self.state_file = Path(self.tmp_dir.name) / "deepseek_thread.json"
        self.store = DeepSeekStateStore(self.state_file)

    def tearDown(self) -> None:
        self.tmp_dir.cleanup()

    def _ok_response(self, text: str = "你好，我是 DeepSeek V4 Flash。") -> httpx.Response:
        return httpx.Response(
            status_code=200,
            text=json.dumps(
                {
                    "choices": [
                        {"message": {"role": "assistant", "content": text}}
                    ]
                }
            ),
            request=httpx.Request("POST", "http://test/chat/completions"),
        )

    async def test_send_success_persists_and_reports_model(self) -> None:
        mock_post = AsyncMock(return_value=self._ok_response())
        with patch("httpx.AsyncClient.post", mock_post):
            adapter = DeepSeekRuntimeAdapter(
                api_key="fake_key",
                state_store=self.store,
                model="deepseek-v4-flash",
                model_name="DeepSeek V4 Flash",
                developer_instructions="保持简洁。",
            )
            reply = await adapter.send("你好")

        self.assertTrue(reply.thread_id.startswith("deepseek_"))
        self.assertTrue(reply.turn_id.startswith("turn_"))
        self.assertEqual("deepseek-v4-flash", reply.model_used)
        self.assertEqual("你好，我是 DeepSeek V4 Flash。", reply.text)

        saved = self.store.load()
        self.assertIsNotNone(saved)
        self.assertEqual(2, len(saved.contents))
        self.assertEqual("user", saved.contents[0]["role"])
        self.assertEqual("assistant", saved.contents[1]["role"])

        # 请求体应包含 system 指令、历史与当前消息
        body = mock_post.call_args.kwargs["json"]
        roles = [m["role"] for m in body["messages"]]
        self.assertEqual(["system", "user"], roles[:2])
        self.assertEqual("deepseek-v4-flash", body["model"])

    async def test_send_raises_on_non_200(self) -> None:
        not_found = httpx.Response(
            status_code=404,
            text="model not found",
            request=httpx.Request("POST", "http://test/chat/completions"),
        )
        with patch("httpx.AsyncClient.post", AsyncMock(return_value=not_found)):
            adapter = DeepSeekRuntimeAdapter(
                api_key="fake_key", state_store=self.store
            )
            with self.assertRaises(Exception) as ctx:
                await adapter.send("hi")
        self.assertIn("HTTP 404", str(ctx.exception))
        # 失败不应污染状态：已建空 state，但不得追加任何消息
        saved = self.store.load()
        self.assertIsNotNone(saved)
        self.assertEqual(0, len(saved.contents))

    async def test_send_empty_reply_raises(self) -> None:
        empty = httpx.Response(
            status_code=200,
            text=json.dumps({"choices": [{"message": {"content": ""}}]}),
            request=httpx.Request("POST", "http://test/chat/completions"),
        )
        with patch("httpx.AsyncClient.post", AsyncMock(return_value=empty)):
            adapter = DeepSeekRuntimeAdapter(
                api_key="fake_key", state_store=self.store
            )
            with self.assertRaises(Exception):
                await adapter.send("hi")


class DeepSeekConfigTest(unittest.TestCase):
    def setUp(self) -> None:
        self.tmp_dir = tempfile.TemporaryDirectory()

    def tearDown(self) -> None:
        self.tmp_dir.cleanup()

    def test_credentials_yaml_parser(self) -> None:
        dsh_dir = Path(self.tmp_dir.name) / ".dsh"
        dsh_dir.mkdir(parents=True)
        fake = dsh_dir / ".credentials.yaml"
        fake.write_text("DEEPSEEK_API_KEY: abc123\n", encoding="utf-8")
        with patch("deepseek_runtime.Path.home", return_value=Path(self.tmp_dir.name)):
            self.assertEqual("abc123", _read_credentials_yaml())

    def test_load_config_returns_none_without_any_key(self) -> None:
        with patch.dict("os.environ", {}, clear=False), patch(
            "deepseek_runtime._read_credentials_yaml", return_value=None
        ), patch("deepseek_runtime._read_pi_auth", return_value=None):
            self.assertIsNone(load_deepseek_config())

    def test_load_config_from_env(self) -> None:
        with patch.dict(
            "os.environ",
            {"DEEPSEEK_API_KEY": "env_key", "DEEPSEEK_MODEL": "deepseek-v4-pro"},
        ):
            config = load_deepseek_config()
        self.assertIsNotNone(config)
        self.assertEqual("env_key", config["api_key"])
        self.assertEqual("deepseek-v4-pro", config["model"])


if __name__ == "__main__":
    unittest.main()
