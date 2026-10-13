"""Tests for the signed Nextcloud Talk integration boundary."""

from __future__ import annotations

import asyncio
import hashlib
import hmac
import json
import sys
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace

WEB_DIR = Path(__file__).resolve().parents[1]
PROJECT_DIR = WEB_DIR.parent
PHASE0_DIR = PROJECT_DIR / "phase0"
sys.path.insert(0, str(WEB_DIR))
sys.path.insert(0, str(PHASE0_DIR))

from talk_bridge import TalkBridge, TalkBridgeError, TalkBridgeStore, verify_signature
from codex_runtime import RuntimeReply
from httpx import ASGITransport, AsyncClient
from server import AppConfig, Participant, create_app


class FakeResponse:
    def __init__(self, status_code: int) -> None:
        self.status_code = status_code


class FakeClient:
    def __init__(self, responses: list[int]) -> None:
        self.responses = responses
        self.posts: list[dict] = []

    async def __aenter__(self):
        return self

    async def __aexit__(self, *args):
        return None

    async def post(self, url: str, **kwargs):
        self.posts.append({"url": url, **kwargs})
        return FakeResponse(self.responses.pop(0) if self.responses else 201)


class FakeService:
    def __init__(self) -> None:
        self.participants = {"gemini": SimpleNamespace(name="Gemini")}
        self.calls: list[dict] = []
        self.reply_number = 0

    async def send(self, content: str, **kwargs):
        self.calls.append({"content": content, **kwargs})
        self.reply_number += 1
        return {
            "reply": {
                "id": f"reply-{self.reply_number}",
                "content": f"reply-{self.reply_number}",
            }
        }


class TalkBridgeTests(unittest.IsolatedAsyncioTestCase):
    def setUp(self) -> None:
        self.temp = tempfile.TemporaryDirectory()
        self.store = TalkBridgeStore(Path(self.temp.name) / "talk.db")
        self.service = FakeService()
        self.clients: list[FakeClient] = []

    def tearDown(self) -> None:
        self.temp.cleanup()

    def client_factory(self):
        client = FakeClient([201])
        self.clients.append(client)
        return client

    def payload(self, message_id: str = "42", text: str = "@Cognos @Gemini hello") -> dict:
        return {
            "type": "Create",
            "actor": {"type": "Person", "id": "users/alice", "name": "Alice"},
            "object": {
                "type": "Note",
                "id": message_id,
                "name": "message",
                "content": json.dumps({"message": text, "parameters": {}}),
                "mediaType": "text/markdown",
            },
            "target": {"type": "Collection", "id": "room-1", "name": "Project"},
        }

    def bridge(self, client_factory=None) -> TalkBridge:
        return TalkBridge(
            service=self.service,
            store=self.store,
            secret="s" * 48,
            nextcloud_url="http://nextcloud.test/",
            default_target="gemini",
            http_client_factory=client_factory or self.client_factory,
        )

    def signed_headers(self, raw: bytes) -> dict[str, str]:
        random_value = "r" * 64
        signature = hmac.new(
            ("s" * 48).encode(), random_value.encode() + raw, hashlib.sha256
        ).hexdigest()
        return {"random": random_value, "signature": signature}

    async def test_unconfigured_app_rejects_webhook_without_claiming_user_auth(self) -> None:
        root = Path(self.temp.name) / "app"
        instructions = root / "instructions.md"
        instructions.parent.mkdir(parents=True, exist_ok=True)
        instructions.write_text("test", encoding="utf-8")
        class Runtime:
            async def send(self, message):
                return SimpleNamespace(thread_id="thread", turn_id="turn", text="reply")

        app = create_app(AppConfig(
            history_path=root / "raw.jsonl",
            thread_state_dir=root / "state",
            codex_cwd=root,
            participant_instructions_path=instructions,
        ), participants={"gemini": Participant(
            id="gemini", name="Gemini", model_name="Gemini", runtime=Runtime()
        )})
        async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
            response = await client.post("/api/integrations/nextcloud-talk/webhook", content=b"{}")
        self.assertEqual(503, response.status_code)

    async def test_signature_and_mention_parsing(self) -> None:
        bridge = self.bridge()
        raw = json.dumps(self.payload(), ensure_ascii=False).encode()
        headers = self.signed_headers(raw)
        self.assertTrue(verify_signature(raw, headers["random"], headers["signature"], "s" * 48))
        self.assertFalse(bridge.verify_request(raw + b"x", headers["random"], headers["signature"]))
        extracted = bridge._extract(self.payload())[0:4]
        self.assertEqual(("@Gemini hello", "Alice", "users/alice", "room-1"), extracted)
        self.assertIsNone(bridge._extract(self.payload(text="hello without a mention")))

    async def test_fastapi_webhook_route_verifies_and_records_external_identity(self) -> None:
        root = Path(self.temp.name) / "route"
        instructions = root / "instructions.md"
        instructions.parent.mkdir(parents=True, exist_ok=True)
        instructions.write_text("test", encoding="utf-8")

        class Runtime:
            async def send(self, message):
                return RuntimeReply(thread_id="thread", turn_id="turn", text="route reply")

        app = create_app(AppConfig(
            history_path=root / "raw.jsonl",
            thread_state_dir=root / "state",
            codex_cwd=root,
            participant_instructions_path=instructions,
            talk_bot_secret="s" * 48,
            talk_nextcloud_url="http://nextcloud.test",
        ), participants={"gemini": Participant(
            id="gemini", name="Gemini", model_name="Gemini", runtime=Runtime()
        )})
        replies = []

        async def fake_reply(room_token, message, event_key, *, reply_to=None):
            replies.append((room_token, message, event_key, reply_to))

        app.state.talk_bridge.send_reply = fake_reply
        payload = self.payload(message_id="route-1")
        raw = json.dumps(payload, ensure_ascii=False).encode()
        headers = self.signed_headers(raw)
        async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
            response = await client.post(
                "/api/integrations/nextcloud-talk/webhook",
                content=raw,
                headers={
                    "x-nextcloud-talk-random": headers["random"],
                    "x-nextcloud-talk-signature": headers["signature"],
                    "content-type": "application/json",
                },
            )
            duplicate = await client.post(
                "/api/integrations/nextcloud-talk/webhook",
                content=raw,
                headers={
                    "x-nextcloud-talk-random": headers["random"],
                    "x-nextcloud-talk-signature": headers["signature"],
                    "content-type": "application/json",
                },
            )
        self.assertEqual(200, response.status_code)
        self.assertEqual("completed", response.json()["status"])
        self.assertEqual(200, duplicate.status_code)
        self.assertEqual("duplicate", duplicate.json()["status"])
        self.assertEqual(1, len(replies))
        self.assertEqual(2, len(app.state.history.read_all()))
        record = app.state.history.read_all()[0]
        self.assertEqual("nextcloud_talk", record["external_source"])
        self.assertEqual("users/alice", record["external_author_id"])
        self.assertEqual("room-1", record["external_room_id"])

    async def test_outgoing_signature_covers_message_field_and_reply_targets_source(self) -> None:
        client = FakeClient([201])
        bridge = self.bridge(lambda: client)
        await bridge.send_reply("room-1", "answer", "talk:room-1:42", reply_to=42)
        posted = client.posts[0]
        body = json.loads(posted["content"])
        random_value = posted["headers"]["X-Nextcloud-Talk-Bot-Random"]
        signature = posted["headers"]["X-Nextcloud-Talk-Bot-Signature"]
        expected = hmac.new(
            ("s" * 48).encode(),
            (random_value + body["message"]).encode(),
            hashlib.sha256,
        ).hexdigest()
        self.assertEqual(42, body["replyTo"])
        self.assertEqual(expected, signature)
        self.assertNotEqual(
            hmac.new(("s" * 48).encode(), random_value.encode() + posted["content"], hashlib.sha256).hexdigest(),
            signature,
        )

    async def test_duplicate_event_does_not_call_model_twice(self) -> None:
        bridge = self.bridge()
        payload = self.payload()
        raw = json.dumps(payload, ensure_ascii=False).encode()
        first = await bridge.handle(payload, raw)
        second = await bridge.handle(payload, raw)
        self.assertEqual("completed", first["status"])
        self.assertEqual("duplicate", second["status"])
        self.assertEqual(1, len(self.service.calls))
        self.assertEqual(1, len(self.clients[0].posts))

    async def test_reply_delivery_failure_is_retryable_without_second_model_turn(self) -> None:
        failed_client = FakeClient([503])
        bridge = self.bridge(lambda: failed_client)
        payload = self.payload()
        raw = json.dumps(payload, ensure_ascii=False).encode()
        with self.assertRaises(TalkBridgeError):
            await bridge.handle(payload, raw)
        self.assertEqual(1, len(self.service.calls))

        retry_client = FakeClient([201])
        retry_bridge = self.bridge(lambda: retry_client)
        result = await retry_bridge.handle(payload, raw)
        self.assertEqual("completed", result["status"])
        self.assertEqual(1, len(self.service.calls))
        self.assertEqual("reply-1", result["reply"])

    async def test_mismatched_payload_under_same_talk_identity_is_rejected(self) -> None:
        bridge = self.bridge()
        first = self.payload(text="@Cognos first")
        raw = json.dumps(first, ensure_ascii=False).encode()
        await bridge.handle(first, raw)
        changed = self.payload(text="@Cognos changed")
        changed_raw = json.dumps(changed, ensure_ascii=False).encode()
        with self.assertRaises(TalkBridgeError):
            await bridge.handle(changed, changed_raw)


if __name__ == "__main__":
    unittest.main()
