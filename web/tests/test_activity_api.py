"""通过真实应用路由检查采用、续接和失败；模型内容用明确的脚本替身。"""

from __future__ import annotations

import json
import sys
import tempfile
import unittest
from pathlib import Path

from httpx import ASGITransport, AsyncClient

WEB_DIR = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(WEB_DIR))
sys.path.insert(0, str(WEB_DIR.parent / "phase0"))

from codex_runtime import RuntimeReply
from server import AppConfig, Participant, create_app


class ScriptedActivityRuntime:
    model = "test-script"

    def __init__(self, responses):
        self.responses = list(responses)
        self.received = []
        self.before_reply = None

    async def send(self, message):
        self.received.append(json.loads(message.split("本次处境（内容材料）：\n", 1)[1]))
        if self.before_reply:
            callback, self.before_reply = self.before_reply, None
            callback()
        response = self.responses.pop(0)
        if isinstance(response, Exception):
            raise response
        text = response if isinstance(response, str) else json.dumps(response, ensure_ascii=False)
        return RuntimeReply(thread_id="activity-test", turn_id=f"turn-{len(self.received)}", text=text)


class ActivityApiTest(unittest.IsolatedAsyncioTestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        root = Path(self.directory.name)
        instructions = root / "instructions.md"
        instructions.write_text("测试参与说明", encoding="utf-8")
        self.config = AppConfig(history_path=root / "vault" / "field.jsonl", thread_state_dir=root / "state",
                                codex_cwd=root, participant_instructions_path=instructions)

    def tearDown(self):
        # 现有 OrgStore/InquiryStore 的连接由 Python GC 回收；新 ActivityStore 显式关闭。
        import gc
        gc.collect()
        self.directory.cleanup()

    async def setup_activity(self, responses):
        runtime = ScriptedActivityRuntime(responses)
        app = create_app(self.config, participants={"test": Participant("test", "测试模型", "脚本替身", runtime)})
        client = AsyncClient(transport=ASGITransport(app=app), base_url="http://test")
        self.addAsyncCleanup(client.aclose)
        org = (await client.post("/api/orgs", json={"name": "测试"})).json()["organization"]
        project = (await client.post(f"/api/orgs/{org['id']}/projects", json={"title": "开放活动"})).json()["project"]
        activity = (await client.post(f"/api/projects/{project['id']}/activities", json={})).json()["activity"]
        response = await client.post(f"/api/activities/{activity['id']}/changes", json={
            "revision": 1, "operations": [{"action": "add_document", "id": "source", "title": "原文",
                "format": "text", "body": "窗还亮着。", "immutable": True}]})
        self.assertEqual(200, response.status_code, response.text)
        return app, client, runtime, response.json()["activity"]

    async def test_actual_changes_return_to_the_model_and_network_retry_is_idempotent(self):
        app, client, runtime, activity = await self.setup_activity([
            {"answer": "", "commands": [{"type": "change", "reason": "试一种不同的文字组织", "operations": [
                {"action": "add_document", "id": "arrangement", "title": "试写", "format": "json",
                 "body": {"op": "replace", "input": {"op": "read", "document": "source"}, "old": "窗", "new": "灯"}},
                {"action": "adopt", "document": "arrangement"},
                {"action": "set_focus", "text": "先看看这一句能怎样延伸"}]}]},
            {"answer": "实际试写已经返回，可以从这里继续。", "commands": []},
        ])
        url = f"/api/activities/{activity['id']}/continue"
        request = {"revision": activity["revision"], "request_id": "one-user-invitation", "content": "先写一点看看", "target": "test"}
        response = await client.post(url, json=request)
        self.assertEqual(200, response.status_code, response.text)
        result = response.json()
        self.assertEqual("paused", result["run"]["status"], result)
        self.assertEqual("灯还亮着。", runtime.received[1]["compiled"]["text"])
        self.assertEqual("先写一点看看", runtime.received[1]["user_message"])
        self.assertEqual("先看看这一句能怎样延伸", runtime.received[1]["focus"])
        self.assertEqual(3, runtime.received[1]["operation_results"][0]["revision"])
        messages = app.state.history.read_all()
        self.assertTrue(any(item.get("activity_operation") and item["operation_results"][0]["status"] == "ok" for item in messages))
        source = result["activity"]["state"]["documents"]["source"]
        self.assertIsNotNone(source["versions"][0]["occurrence_id"])
        retried = await client.post(url, json=request)
        self.assertEqual(result["run"]["id"], retried.json()["run"]["id"])
        self.assertEqual(2, len(runtime.received))
        self.assertEqual(len(messages), len(app.state.history.read_all()))

    async def test_concurrent_edit_is_visible_and_not_overwritten_by_old_reply(self):
        app, client, runtime, activity = await self.setup_activity([
            {"answer": "依据旧内容修改", "commands": [{"type": "change", "operations": [{"action": "set_focus", "text": "旧回复"}]}]},
            {"answer": "已看见当前修改", "commands": []},
        ])
        runtime.before_reply = lambda: app.state.activity_store.change(activity["id"], activity["revision"],
            [{"action": "set_focus", "text": "用户期间修改"}], actor="小林")
        response = await client.post(f"/api/activities/{activity['id']}/continue", json={
            "revision": activity["revision"], "request_id": "concurrent", "target": "test"})
        result = response.json()
        self.assertEqual("paused", result["run"]["status"], result)
        self.assertEqual("conflict", result["run"]["events"][0]["results"][0]["status"])
        self.assertEqual("用户期间修改", runtime.received[1]["focus"])
        self.assertEqual("用户期间修改", result["activity"]["state"]["focus"])

    async def test_budget_pause_restores_state_and_previous_real_results(self):
        app, client, runtime, activity = await self.setup_activity([
            {"answer": "先读取", "commands": [{"type": "read", "document": "source"}]},
            {"answer": "继续面对已取得的原文", "commands": []},
        ])
        app.state.activity_service.max_calls = 1
        url = f"/api/activities/{activity['id']}/continue"
        first = (await client.post(url, json={"revision": activity["revision"], "request_id": "bounded", "target": "test"})).json()
        self.assertEqual("budget_reached", first["run"]["status"])
        second = (await client.post(url, json={"revision": first["activity"]["revision"], "request_id": "continue", "target": "test"})).json()
        self.assertEqual("paused", second["run"]["status"])
        previous = runtime.received[1]["operation_results"][0]
        self.assertEqual(first["run"]["id"], previous["previous_run"])
        self.assertEqual("窗还亮着。", previous["results"][0]["document"]["body"])

    async def test_protocol_error_and_runtime_failure_preserve_original_occurrences(self):
        app, client, runtime, activity = await self.setup_activity(["无法解析的模型回复", RuntimeError("offline")])
        url = f"/api/activities/{activity['id']}/continue"
        first = (await client.post(url, json={"revision": activity["revision"], "request_id": "invalid", "content": "原始问题", "target": "test"})).json()
        self.assertEqual("protocol_error", first["run"]["status"])
        self.assertEqual(activity["revision"], first["activity"]["revision"])
        second = (await client.post(url, json={"revision": activity["revision"], "request_id": "offline", "content": "仍然保留", "target": "test"})).json()
        self.assertEqual("failed", second["run"]["status"])
        contents = [item["content"] for item in app.state.history.read_all()]
        self.assertIn("无法解析的模型回复", contents)
        self.assertIn("原始问题", contents)
        self.assertIn("仍然保留", contents)

    async def test_import_uses_real_occurrence_and_keeps_its_source(self):
        app, client, runtime, activity = await self.setup_activity([])
        original = app.state.history.append_message(author="已有参与者", content="一次真实返回的正文")
        response = await client.post(f"/api/activities/{activity['id']}/imports", json={
            "revision": activity["revision"], "occurrence_id": original["id"], "title": "已有结果"})
        self.assertEqual(200, response.status_code, response.text)
        imported = [item for item in response.json()["activity"]["state"]["documents"].values() if item["title"] == "已有结果"][0]
        self.assertTrue(imported["immutable"])
        self.assertEqual(original["id"], imported["versions"][0]["occurrence_id"])
        self.assertEqual("已有参与者", imported["versions"][0]["source_author"])
        self.assertEqual("一次真实返回的正文", imported["versions"][0]["body"])
        from activity_service import build_activity_input
        _, context = build_activity_input(response.json()["activity"], "", [])
        entry = next(item for item in context["material_catalog"] if item["title"] == "已有结果")
        self.assertEqual("已有参与者", entry["source_author"])
        self.assertEqual(original["id"], entry["occurrence_id"])

    async def test_codex_trial_keeps_actual_initiator(self):
        app, client, runtime, activity = await self.setup_activity([{"answer": "试用回复", "commands": []}])
        result = await app.state.activity_service.continue_activity(activity["id"], content="由 Codex 发起的试用",
            target="test", revision=activity["revision"], request_id="codex-trial", initiator="Codex（设计试用）")
        record = next(item for item in app.state.history.read_all() if item["id"] == result["user_occurrence_id"])
        self.assertEqual("Codex（设计试用）", record["author"])
        self.assertTrue(any(item["author"] == "Codex（设计试用）" for item in runtime.received[0]["recent_activity"]))

    async def setup_source(self, app, client, activity):
        first = app.state.history.append_message(author="材料作者", content="比例与整体的学习笔记", project_id=activity["project_id"])
        second = app.state.history.append_message(author="执行者", content="实际返回：作品中的并置", project_id=activity["project_id"], handoff_result={"status": "returned"})
        app.state.history.append_message(author="其他项目", content="比例与整体的学习笔记", project_id="other-project")
        response = await client.post(f"/api/activities/{activity['id']}/changes", json={"revision": activity["revision"], "operations": [
            {"action": "add_document", "id": "topic", "title": "当前取得方向", "format": "text", "body": "比例"},
            {"action": "add_document", "id": "source-plan", "format": "json", "body": {"op": "object", "fields": {
                "provider": {"op": "literal", "value": "project_history"}, "contains": {"op": "read", "document": "topic"}}}},
            {"action": "adopt_source", "document": "source-plan"}]})
        self.assertEqual(200, response.status_code, response.text)
        return response.json()["activity"], first, second

    async def test_adopted_acquisition_changes_real_material_and_retry_does_not_repeat(self):
        app, client, runtime, activity = await self.setup_activity([])
        activity, first, second = await self.setup_source(app, client, activity)
        count = len(app.state.history.read_all())
        prefix = f"/api/activities/{activity['id']}"
        preview = (await client.get(prefix + "/acquisition")).json()
        self.assertEqual("比例", preview["request"]["contains"])
        self.assertEqual(count, len(app.state.history.read_all()))
        body = {"revision": activity["revision"], "request_id": "actual-source"}
        acquired = (await client.post(prefix + "/acquire", json=body)).json()
        self.assertEqual("acquired", acquired["run"]["status"], acquired)
        result = acquired["run"]["events"][0]["results"][0]
        self.assertEqual([first["id"]], [record["occurrence_id"] for record in result["acquisition"]["records"]])
        self.assertTrue(acquired["activity"]["state"]["documents"][result["document"]]["immutable"])
        retried = (await client.post(prefix + "/acquire", json=body)).json()
        self.assertEqual(acquired["run"]["id"], retried["run"]["id"])
        self.assertEqual(acquired["activity"]["revision"], retried["activity"]["revision"])
        self.assertEqual([], runtime.received)
        changed = (await client.post(prefix + "/changes", json={"revision": acquired["activity"]["revision"], "operations": [
            {"action": "revise_document", "document": "topic", "body": "并置"}]})).json()["activity"]
        other = (await client.post(prefix + "/acquire", json={"revision": changed["revision"], "request_id": "new-direction"})).json()
        records = other["run"]["events"][0]["results"][0]["acquisition"]["records"]
        self.assertEqual([second["id"]], [record["occurrence_id"] for record in records])
        self.assertIn(result["document"], other["activity"]["state"]["documents"])

    async def test_model_receives_actual_acquisition_results_in_same_activity(self):
        app, client, runtime, activity = await self.setup_activity([
            {"answer": "取得当前组织指定的材料", "commands": [{"type": "acquire"}]},
            {"answer": "现在可以依据返回的原文继续", "commands": []}])
        activity, first, second = await self.setup_source(app, client, activity)
        result = (await client.post(f"/api/activities/{activity['id']}/continue", json={
            "revision": activity["revision"], "request_id": "acquisition-in-activity", "target": "test"})).json()
        self.assertEqual("paused", result["run"]["status"], result)
        record = runtime.received[1]["operation_results"][0]["acquisition"]["records"][0]
        self.assertEqual(first["id"], record["occurrence_id"])
        self.assertEqual("材料作者", record["author"])
        self.assertEqual("比例与整体的学习笔记", record["content"])
        self.assertEqual("比例", runtime.received[1]["acquisition"]["request"]["contains"])

    async def test_acquisition_revision_and_scope_contracts(self):
        app, client, runtime, activity = await self.setup_activity([])
        activity, first, second = await self.setup_source(app, client, activity)
        prefix = f"/api/activities/{activity['id']}"
        changed = (await client.post(prefix + "/changes", json={"revision": activity["revision"], "operations": [
            {"action": "revise_document", "document": "source-plan", "body": {"op": "literal", "value": {
                "provider": "project_history", "kind": "handoff_result"}}}]})).json()["activity"]
        self.assertEqual("比例", (await client.get(prefix + "/acquisition")).json()["request"]["contains"])
        stale = (await client.post(prefix + "/acquire", json={"revision": activity["revision"], "request_id": "stale-acquire"})).json()
        self.assertEqual("conflict", stale["run"]["status"])
        adopted = (await client.post(prefix + "/changes", json={"revision": changed["revision"], "operations": [
            {"action": "adopt_source", "document": "source-plan"}]})).json()["activity"]
        self.assertEqual("handoff_result", (await client.get(prefix + "/acquisition")).json()["request"]["kind"])
        results_only = (await client.post(prefix + "/acquire", json={"revision": adopted["revision"], "request_id": "results-only"})).json()
        self.assertEqual([second["id"]], [record["occurrence_id"] for record in results_only["run"]["events"][0]["results"][0]["acquisition"]["records"]])
        adopted = results_only["activity"]
        invalid = await client.post(prefix + "/changes", json={"revision": adopted["revision"], "operations": [
            {"action": "revise_document", "document": "source-plan", "body": {"op": "literal", "value": {
                "provider": "project_history", "project_id": "other-project"}}},
            {"action": "adopt_source", "document": "source-plan"}]})
        self.assertEqual(422, invalid.status_code)
        self.assertEqual(adopted["revision"], (await client.get(prefix)).json()["activity"]["revision"])

    def test_acquisition_marks_excerpts_and_does_not_search_its_own_receipts(self):
        from activity_acquisition import collect_project_history
        content = "开头" * 4000 + "[一个需重读的差异]" + "结尾" * 4000
        original = {"id":"original", "project_id":"p", "author":"原作者", "content":content}
        receipt = {"id":"receipt", "project_id":"p", "author":"Cognos", "content":"[一个需重读的差异]", "activity_acquisition":True}
        returned = collect_project_history([original, receipt], "p", {"provider":"project_history", "contains":"[一个需重读的差异]"})
        self.assertEqual(1, returned["matched_count"])
        excerpt = returned["records"][0]
        self.assertTrue(excerpt["content_truncated"])
        self.assertEqual(content[excerpt["excerpt_start"]:excerpt["excerpt_start"] + 6000], excerpt["content"])
        self.assertIn("[一个需重读的差异]", excerpt["content"])


    async def test_situation_and_move_bind_to_generated_revision(self):
        app, client, runtime, activity = await self.setup_activity([
            {"answer": "先形成一个还很粗的理解。", "situation": {"text": "这一句可能和重复有关。", "refs": [
                {"document": "source", "excerpt": "窗还亮着。", "note": "目前唯一的材料"}]},
             "move": {"intent": "把理解写下来之后，实际试写一个替换。", "execution": [{"type": "change", "reason": "试替换",
                "operations": [{"action": "add_document", "id": "draft", "title": "试写", "format": "text", "body": "灯还亮着。"}]}]}},
            {"answer": "继续", "commands": []},
        ])
        result = (await client.post(f"/api/activities/{activity['id']}/continue", json={
            "revision": activity["revision"], "request_id": "situation-move", "target": "test"})).json()
        self.assertEqual("paused", result["run"]["status"], result)
        final = result["activity"]
        self.assertEqual("这一句可能和重复有关。", final["state"]["situation"]["text"])
        move = app.state.activity_store.list_moves(final["id"])[0]
        situation_revision = result["run"]["events"][0]["situation_revision"]
        self.assertEqual(situation_revision, move["basis_revision"])  # 绑定到 situation 实际生成的版本
        self.assertEqual("done", move["state"]["status"])
        self.assertIn("draft", final["state"]["documents"])
        # 下一轮输入里带着当前理解与已执行的 Move
        self.assertEqual("这一句可能和重复有关。", runtime.received[1]["situation"]["text"])
        self.assertEqual(move["id"], runtime.received[1]["moves"][0]["id"])

    async def test_ask_user_waits_and_only_real_answer_becomes_result(self):
        app, client, runtime, activity = await self.setup_activity([
            {"answer": "我需要先知道一件事。", "move": {"intent": "缺少一个真实条件", "execution": [
                {"type": "ask_user", "question": "你最初看到这句话时想到的是什么？"}]}},
            {"answer": "现在知道你的回答了，可以继续。", "situation": {"text": "回答补充了一个此前没有的条件。"}},
        ])
        url = f"/api/activities/{activity['id']}/continue"
        result = (await client.post(url, json={
            "revision": activity["revision"], "request_id": "asking", "content": "开始", "target": "test"})).json()
        self.assertEqual("waiting_user", result["run"]["status"], result)
        move = app.state.activity_store.list_moves(activity["id"])[0]
        self.assertEqual("waiting_user", move["state"]["status"])
        self.assertEqual("你最初看到这句话时想到的是什么？", move["state"]["waiting"]["question"])
        self.assertEqual(1, len(runtime.received))  # 本轮模型运行已结束，不循环假装停留
        answer_url = f"/api/activities/{activity['id']}/moves/{move['id']}/answer"
        answer = (await client.post(answer_url, json={
            "revision": result["activity"]["revision"], "request_id": "real-answer",
            "answer": "想到了亮着的房间。"})).json()
        self.assertEqual("paused", answer["run"]["status"], answer)
        move_after = app.state.activity_store.get_move(move["id"])
        self.assertEqual("done", move_after["state"]["status"])
        saved = move_after["state"]["results"][-1]
        self.assertEqual("user_answer", saved["kind"])
        self.assertEqual("想到了亮着的房间。", saved["content"])
        self.assertEqual("小林", saved["author"])
        self.assertIn("想到了亮着的房间。", runtime.received[1]["user_message"])
        self.assertEqual("回答补充了一个此前没有的条件。", answer["activity"]["state"]["situation"]["text"])
        retried = (await client.post(answer_url, json={
            "revision": result["activity"]["revision"], "request_id": "real-answer",
            "answer": "想到了亮着的房间。"})).json()
        self.assertEqual(answer["run"]["id"], retried["run"]["id"])
        self.assertEqual(2, len(runtime.received))

    async def test_pause_without_move_keeps_state_and_creates_nothing(self):
        app, client, runtime, activity = await self.setup_activity([
            {"answer": "这次先停下。", "situation": {"text": "尚不清楚。"}},
        ])
        result = (await client.post(f"/api/activities/{activity['id']}/continue", json={
            "revision": activity["revision"], "request_id": "pause", "target": "test"})).json()
        self.assertEqual("paused", result["run"]["status"], result)
        self.assertEqual([], app.state.activity_store.list_moves(activity["id"]))
        self.assertEqual("尚不清楚。", result["activity"]["state"]["situation"]["text"])
        self.assertEqual(1, len(runtime.received))

    async def test_create_step_saves_model_generated_material_with_source(self):
        app, client, runtime, activity = await self.setup_activity([
            {"answer": "试着写一段。", "move": {"intent": "先写下一小段", "execution": [
                {"type": "create", "title": "第一段", "format": "text", "body": "灯亮着，像有人刚离开。"}]}},
            {"answer": "可以再从这段出发。", "commands": []},
        ])
        result = (await client.post(f"/api/activities/{activity['id']}/continue", json={
            "revision": activity["revision"], "request_id": "create", "target": "test"})).json()
        self.assertEqual("paused", result["run"]["status"], result)
        documents = result["activity"]["state"]["documents"]
        created = next(doc for doc in documents.values() if doc["title"] == "第一段")
        self.assertEqual("灯亮着，像有人刚离开。", created["versions"][0]["body"])
        self.assertEqual("模型生成 · 测试模型", created["versions"][0]["source_author"])
        self.assertFalse(created["immutable"])
        move = app.state.activity_store.list_moves(activity["id"])[0]
        self.assertEqual("done", move["state"]["status"])


if __name__ == "__main__":
    unittest.main()
