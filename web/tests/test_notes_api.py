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

from server import AppConfig, Participant, create_app
from test_server import FakeRuntime
from codex_runtime import RuntimeReply
from test_helpers import login_test_user


class NotesApiTest(unittest.IsolatedAsyncioTestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        root = Path(self.tmp.name)
        instructions = root / "participant.md"
        instructions.write_text("test", encoding="utf-8")
        self.config = AppConfig(
            history_path=root / "vault" / "first.jsonl",
            thread_state_dir=root / "state",
            codex_cwd=root,
            participant_instructions_path=instructions,
        )

    def tearDown(self):
        self.tmp.cleanup()

    async def test_notes_space_round_trip_and_link_boundaries(self):
        app = create_app(self.config, runtime=FakeRuntime())
        async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
            denied = await client.post("/api/notes/spaces", json={"title": "No auth"})
            self.assertEqual(401, denied.status_code)
            await login_test_user(client)
            space = (await client.post("/api/notes/spaces", json={"title": "空间一"})).json()["space"]
            other = (await client.post("/api/notes/spaces", json={"title": "空间二"})).json()["space"]
            source = {"source_root": "Obsidian Vault", "source_path": "a.md", "content_hash": "abc", "immutable": True}
            first = (await client.post(f"/api/notes/spaces/{space['id']}/notes", json={"title": "来源 A", "content": "原文 A", "source": source})).json()["note"]
            second = (await client.post(f"/api/notes/spaces/{space['id']}/notes", json={"title": "笔记 B", "content": "原文 B"})).json()["note"]
            other_note = (await client.post(f"/api/notes/spaces/{other['id']}/notes", json={"title": "其他", "content": "x"})).json()["note"]
            link = await client.post(f"/api/notes/spaces/{space['id']}/links", json={"source_note_id": first["id"], "target_note_id": second["id"], "label": "继续"})
            self.assertEqual(200, link.status_code)
            duplicate = await client.post(f"/api/notes/spaces/{space['id']}/links", json={"source_note_id": first["id"], "target_note_id": second["id"], "label": "继续"})
            self.assertEqual(200, duplicate.status_code)
            rejected = await client.post(f"/api/notes/spaces/{space['id']}/links", json={"source_note_id": first["id"], "target_note_id": other_note["id"]})
            self.assertEqual(422, rejected.status_code)
            detail = (await client.get(f"/api/notes/spaces/{space['id']}")).json()["space"]
            self.assertEqual(2, len(detail["notes"]))
            self.assertEqual(1, len(detail["links"]))
            self.assertEqual(source, next(n for n in detail["notes"] if n["id"] == first["id"])["source"])
            await client.patch(f"/api/notes/{first['id']}", json={"title": "来源 A 修订", "content": "新正文"})
            reopened = (await client.get(f"/api/notes/spaces/{space['id']}")).json()["space"]
            changed = next(n for n in reopened["notes"] if n["id"] == first["id"])
            self.assertEqual("新正文", changed["content"])
            self.assertEqual("abc", changed["source"]["content_hash"])

    async def test_note_space_message_reaches_selected_agent_and_persists(self):
        participants = {
            "codex": Participant(
                id="codex", name="Codex", model_name="Codex Test",
                runtime=FakeRuntime(prefix="agent", thread_id="agent-thread"),
            )
        }
        app = create_app(self.config, participants=participants)
        async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
            await login_test_user(client)
            space = (await client.post("/api/notes/spaces", json={"title": "可对话空间"})).json()["space"]
            note = (await client.post(
                f"/api/notes/spaces/{space['id']}/notes",
                json={"title": "原始笔记", "content": "不同环境下三次失败，原因未明。"},
            )).json()["note"]
            response = await client.post(
                f"/api/notes/spaces/{space['id']}/messages",
                json={"content": "请围绕当前笔记继续", "target": "codex", "note_id": note["id"]},
            )
            self.assertEqual(200, response.status_code)
            self.assertEqual("Codex", response.json()["reply"]["author"])
            detail = (await client.get(f"/api/notes/spaces/{space['id']}")).json()
            messages = detail["messages"]
            self.assertEqual(2, len(messages))
            self.assertEqual("小林", messages[0]["author"])
            self.assertEqual("Codex", messages[1]["author"])
            received = app.state.participants["codex"].runtime.calls[-1]
            self.assertIn("不同环境下三次失败，原因未明。", received)
            self.assertIn("请围绕当前笔记继续", received)
            self.assertIn("可对话空间", received)

    async def test_agent_failure_keeps_user_input_and_source_note(self):
        class BrokenRuntime:
            async def send(self, message):
                raise RuntimeError("provider offline")

        app = create_app(self.config, participants={
            "codex": Participant(id="codex", name="Codex", model_name="Codex Test", runtime=BrokenRuntime())
        })
        async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
            await login_test_user(client)
            space = (await client.post("/api/notes/spaces", json={"title": "失败恢复"})).json()["space"]
            note = (await client.post(f"/api/notes/spaces/{space['id']}/notes", json={"title": "原文", "content": "不要覆盖我"})).json()["note"]
            failed = await client.post(f"/api/notes/spaces/{space['id']}/messages", json={"content": "继续讨论", "target": "codex", "note_id": note["id"]})
            self.assertEqual(502, failed.status_code)
            self.assertIn("消息已保存在笔记空间", failed.json()["detail"])
            detail = (await client.get(f"/api/notes/spaces/{space['id']}")).json()
            self.assertEqual("不要覆盖我", detail["space"]["notes"][0]["content"])
            self.assertEqual(["继续讨论"], [m["content"] for m in detail["messages"]])

    async def test_retry_last_message_does_not_duplicate_user_turn(self):
        class RecoveringRuntime:
            def __init__(self):
                self.calls = 0

            async def send(self, message):
                self.calls += 1
                if self.calls == 1:
                    raise RuntimeError("provider offline")
                return RuntimeReply(thread_id="retry-thread", turn_id="retry-turn", text="恢复后的回复")

        runtime = RecoveringRuntime()
        app = create_app(self.config, participants={
            "codex": Participant(id="codex", name="Codex", model_name="Codex Test", runtime=runtime)
        })
        async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
            await login_test_user(client)
            space = (await client.post("/api/notes/spaces", json={"title": "重试"})).json()["space"]
            failed = await client.post(
                f"/api/notes/spaces/{space['id']}/messages",
                json={"content": "请继续", "target": "codex"},
            )
            self.assertEqual(502, failed.status_code)
            messages = (await client.get(f"/api/notes/spaces/{space['id']}" )).json()["messages"]
            self.assertEqual(1, len(messages))
            retried = await client.post(
                f"/api/notes/spaces/{space['id']}/messages/{messages[0]['id']}/retry",
                json={"target": "codex"},
            )
            self.assertEqual(200, retried.status_code, retried.text)
            self.assertEqual(messages[0]["id"], retried.json()["reply"]["reply_to"])
            messages = (await client.get(f"/api/notes/spaces/{space['id']}" )).json()["messages"]
            self.assertEqual(2, len(messages))
            self.assertEqual("请继续", messages[0]["content"])
            self.assertEqual("恢复后的回复", messages[1]["content"])

    async def test_note_can_start_project_activity_with_immutable_source_snapshot(self):
        app = create_app(self.config, runtime=FakeRuntime())
        async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
            await login_test_user(client)
            space = (await client.post("/api/notes/spaces", json={"title": "起点空间"})).json()["space"]
            note = (await client.post(
                f"/api/notes/spaces/{space['id']}/notes",
                json={"title": "尚未形成", "content": "第一版原文", "source": {"source_root": "probe", "immutable": True}},
            )).json()["note"]
            org = (await client.post("/api/orgs", json={"name": "起点组织"})).json()["organization"]
            project = (await client.post(
                f"/api/orgs/{org['id']}/projects", json={"title": "起点项目"}
            )).json()["project"]
            started = await client.post(
                f"/api/notes/{space['id']}/notes/{note['id']}/activity",
                json={"project_id": project["id"], "title": "围绕尚未形成继续"},
            )
            self.assertEqual(200, started.status_code)
            activity = started.json()["activity"]
            self.assertEqual(note["id"], started.json()["source_note"]["note_id"])
            document = next(iter(activity["state"]["documents"].values()))
            self.assertEqual("第一版原文", document["versions"][0]["body"])
            self.assertEqual(note["id"], document["source_ref"]["note_id"])
            repeated = await client.post(
                f"/api/notes/{space['id']}/notes/{note['id']}/activity",
                json={"project_id": project["id"], "title": "重复点击"},
            )
            self.assertEqual(200, repeated.status_code)
            self.assertTrue(repeated.json()["reused"])
            self.assertEqual(activity["id"], repeated.json()["activity"]["id"])
            await client.patch(f"/api/notes/{note['id']}", json={"title": "已补充", "content": "第二版原文"})
            reopened = (await client.get(f"/api/activities/{activity['id']}")).json()["activity"]
            self.assertEqual("第一版原文", next(iter(reopened["state"]["documents"].values()))["versions"][0]["body"])

    async def test_note_activity_start_rejects_other_users_note(self):
        app = create_app(self.config, runtime=FakeRuntime())
        async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as owner:
            await login_test_user(owner, username="owner_start", display_name="Owner")
            space = (await owner.post("/api/notes/spaces", json={"title": "私有起点"})).json()["space"]
            note = (await owner.post(f"/api/notes/spaces/{space['id']}/notes", json={"title": "私有", "content": "x"})).json()["note"]
            org = (await owner.post("/api/orgs", json={"name": "私有组织"})).json()["organization"]
            project = (await owner.post(f"/api/orgs/{org['id']}/projects", json={"title": "私有项目"})).json()["project"]
        async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as outsider:
            await login_test_user(outsider, username="outsider_start", display_name="Outsider")
            rejected = await outsider.post(
                f"/api/notes/{space['id']}/notes/{note['id']}/activity",
                json={"project_id": project["id"]},
            )
            self.assertEqual(404, rejected.status_code)

    async def test_note_patch_requires_owner_and_revision_opens_new_activity_snapshot(self):
        app = create_app(self.config, runtime=FakeRuntime())
        async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as owner:
            owner_account = await login_test_user(owner, username="note_revision_owner", display_name="Owner")
            space = (await owner.post("/api/notes/spaces", json={"title": "版本空间"})).json()["space"]
            note = (await owner.post(
                f"/api/notes/spaces/{space['id']}/notes",
                json={"title": "版本一", "content": "第一版"},
            )).json()["note"]
            org = (await owner.post("/api/orgs", json={"name": "版本组织"})).json()["organization"]
            project = (await owner.post(
                f"/api/orgs/{org['id']}/projects", json={"title": "版本项目"}
            )).json()["project"]
            first = (await owner.post(
                f"/api/notes/{space['id']}/notes/{note['id']}/activity",
                json={"project_id": project["id"]},
            )).json()["activity"]
            revised = (await owner.patch(
                f"/api/notes/{note['id']}",
                json={"title": "版本二", "content": "第二版"},
            )).json()["note"]
            self.assertEqual(2, revised["revision"])
            second_response = await owner.post(
                f"/api/notes/{space['id']}/notes/{note['id']}/activity",
                json={"project_id": project["id"]},
            )
            self.assertEqual(200, second_response.status_code)
            second = second_response.json()["activity"]
            self.assertNotEqual(first["id"], second["id"])
            self.assertEqual("第二版", next(iter(second["state"]["documents"].values()))["versions"][0]["body"])
        async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as outsider:
            await login_test_user(outsider, username="note_revision_outsider", display_name="Outsider")
            denied = await outsider.patch(
                f"/api/notes/{note['id']}",
                json={"title": "越权", "content": "不应修改"},
            )
            self.assertEqual(404, denied.status_code)


if __name__ == "__main__":
    unittest.main()
