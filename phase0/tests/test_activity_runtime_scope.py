"""Transport evidence and activity isolation, with no external model calls."""

import json
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import AsyncMock, patch

import httpx

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from deepseek_runtime import DeepSeekRuntimeAdapter, DeepSeekStateStore, DeepSeekConversationState
from gemini_runtime import GeminiRuntimeAdapter, GeminiStateStore, GeminiConversationState
from codex_runtime import RuntimeUnavailableError


class ActivityRuntimeScopeTest(unittest.IsolatedAsyncioTestCase):
    def binding(self, provider, root):
        path = root / f"{provider}.json"
        if provider == "gemini":
            store = GeminiStateStore(path)
            state = GeminiConversationState("chat-original", [
                {"role": "user", "parts": [{"text": "private-chat-sentinel"}]}])
            adapter = GeminiRuntimeAdapter(api_key="credential-sentinel", state_store=store,
                                           developer_instructions="fixed instructions")
            adapter.allow_fallback = False
            reply = {"candidates": [{"content": {"parts": [{"text": "reply"}]}}]}
        else:
            store = DeepSeekStateStore(path)
            state = DeepSeekConversationState("chat-original", [
                {"role": "user", "content": "private-chat-sentinel"}])
            adapter = DeepSeekRuntimeAdapter(api_key="credential-sentinel", state_store=store,
                                             developer_instructions="fixed instructions")
            reply = {"model": "reported-model", "choices": [{"message": {"content": "reply"}}]}
        store.save(state)
        adapter._get_or_create_state()  # Also test that a live cached chat is untouched.
        return adapter, path, reply

    async def test_activity_neither_reads_nor_writes_chat_and_records_actual_body(self):
        with tempfile.TemporaryDirectory() as directory:
            for provider in ("gemini", "compatible"):
                with self.subTest(provider=provider):
                    adapter, path, body = self.binding(provider, Path(directory))
                    original = path.read_bytes()
                    records = []
                    async def post(url, **kwargs):
                        self.assertEqual(records[-1]["payload"], kwargs["json"])
                        self.assertNotIn("private-chat-sentinel", json.dumps(kwargs["json"]))
                        return httpx.Response(200, json=body)
                    with patch("httpx.AsyncClient.post", side_effect=post):
                        reply = await adapter.send_activity("current activity", on_request=records.append)
                    self.assertEqual(original, path.read_bytes())
                    self.assertEqual("chat-original", adapter._cached_state.thread_id)
                    self.assertEqual(1, len(adapter._cached_state.contents))
                    self.assertNotEqual("chat-original", reply.thread_id)
                    self.assertIn("fixed instructions", json.dumps(records))
                    self.assertNotIn("credential-sentinel", json.dumps(records))
                    # Ordinary chat still resumes its original continuity afterwards.
                    mock = AsyncMock(return_value=httpx.Response(200, json=body))
                    with patch("httpx.AsyncClient.post", mock):
                        await adapter.send("ordinary follow-up")
                    self.assertIn("private-chat-sentinel", json.dumps(mock.call_args.kwargs["json"]))
                    self.assertNotIn("current activity", json.dumps(mock.call_args.kwargs["json"]))

    async def test_failed_retries_are_captured_without_mutating_chat(self):
        with tempfile.TemporaryDirectory() as directory:
            for provider in ("gemini", "compatible"):
                adapter, path, _ = self.binding(provider, Path(directory))
                original = path.read_bytes()
                records = []
                mock = AsyncMock(return_value=httpx.Response(503, text="unavailable"))
                with patch("httpx.AsyncClient.post", mock), patch("asyncio.sleep", new_callable=AsyncMock):
                    with self.assertRaises(RuntimeUnavailableError):
                        await adapter.send_activity("current activity", on_request=records.append)
                self.assertEqual([1, 2], [r["attempt"] for r in records])
                self.assertEqual(2, mock.call_count)
                self.assertEqual(original, path.read_bytes())

    async def test_snapshot_failure_prevents_network_request(self):
        with tempfile.TemporaryDirectory() as directory:
            for provider in ("gemini", "compatible"):
                adapter, _, _ = self.binding(provider, Path(directory))
                def fail(_):
                    raise OSError("snapshot unavailable")
                with patch("httpx.AsyncClient.post", new_callable=AsyncMock) as mock:
                    with self.assertRaises(OSError):
                        await adapter.send_activity("current activity", on_request=fail)
                    mock.assert_not_called()

    async def test_missing_response_model_stays_unknown(self):
        with tempfile.TemporaryDirectory() as directory:
            adapter, _, body = self.binding("compatible", Path(directory))
            body.pop("model")
            with patch("httpx.AsyncClient.post", new_callable=AsyncMock,
                       return_value=httpx.Response(200, json=body)):
                reply = await adapter.send_activity("current activity", on_request=lambda _: None)
            self.assertIsNone(reply.model_used)
            self.assertEqual(adapter.model, reply.requested_model)
