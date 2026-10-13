"""Registration directory, organization invitations, and direct-message journeys."""

from __future__ import annotations

import sys
import tempfile
import unittest
from pathlib import Path

from httpx import ASGITransport, AsyncClient

WEB_DIR = Path(__file__).resolve().parents[1]
PROJECT_DIR = WEB_DIR.parent
sys.path.insert(0, str(WEB_DIR))
sys.path.insert(0, str(PROJECT_DIR / "phase0"))

from codex_runtime import RuntimeReply
from server import AppConfig, Participant, create_app


class Runtime:
    async def send(self, message: str) -> RuntimeReply:
        return RuntimeReply(thread_id="social-thread", turn_id="social-turn", text="ok")


class SocialWorkflowTest(unittest.IsolatedAsyncioTestCase):
    def setUp(self) -> None:
        self.tmp = tempfile.TemporaryDirectory()
        root = Path(self.tmp.name)
        instructions = root / "instructions.md"
        instructions.write_text("test", encoding="utf-8")
        self.config = AppConfig(
            history_path=root / "vault" / "raw.jsonl",
            thread_state_dir=root / "state",
            codex_cwd=root,
            participant_instructions_path=instructions,
        )
        self.app = create_app(self.config, participants={
            "codex": Participant("codex", "Codex", "Codex", Runtime())
        })

    def tearDown(self) -> None:
        self.tmp.cleanup()

    async def client(self, username: str, password: str, display_name: str) -> AsyncClient:
        client = AsyncClient(transport=ASGITransport(app=self.app), base_url="http://test")
        registered = await client.post("/api/auth/register", json={
            "username": username, "password": password, "display_name": display_name,
        })
        self.assertEqual(200, registered.status_code)
        return client

    async def test_search_invite_accept_and_direct_message_persist_after_relogin(self) -> None:
        alice = await self.client("alice", "alice123", "Alice")
        bob = await self.client("bob", "bob1234", "Bob")
        try:
            found = await alice.get("/api/auth/accounts?q=bo")
            self.assertEqual(["bob"], [item["username"] for item in found.json()["accounts"]])

            org = (await alice.post("/api/orgs", json={"name": "真实团队"})).json()["organization"]
            invitation = await alice.post(
                f"/api/orgs/{org['id']}/invitations",
                json={"invitee_account_id": found.json()["accounts"][0]["id"]},
            )
            self.assertEqual(200, invitation.status_code)
            pending = (await bob.get("/api/invitations")).json()["invitations"]
            self.assertEqual("pending", pending[0]["status"])
            accepted = await bob.post(
                f"/api/invitations/{pending[0]['id']}/respond", json={"accept": True}
            )
            self.assertEqual("accepted", accepted.json()["invitation"]["status"])
            org_detail = (await bob.get(f"/api/orgs/{org['id']}")).json()
            self.assertEqual({"Alice", "Bob"}, {member["name"] for member in org_detail["members"]})

            dm = await alice.post(
                "/api/direct-conversations", json={"account_id": found.json()["accounts"][0]["id"]}
            )
            self.assertEqual(200, dm.status_code)
            conversation_id = dm.json()["conversation"]["id"]
            sent = await alice.post(
                f"/api/direct-conversations/{conversation_id}/messages",
                json={"content": "我们先在这里确认加入。"},
            )
            self.assertEqual(200, sent.status_code)
            messages = (await bob.get(f"/api/direct-conversations/{conversation_id}/messages")).json()["messages"]
            self.assertEqual("我们先在这里确认加入。", messages[0]["content"])

            await bob.post("/api/auth/logout")
            await bob.post("/api/auth/login", json={"username": "bob", "password": "bob1234"})
            again = (await bob.get("/api/direct-conversations")).json()["conversations"]
            self.assertEqual(conversation_id, again[0]["id"])
        finally:
            await alice.aclose()
            await bob.aclose()


if __name__ == "__main__":
    unittest.main()
