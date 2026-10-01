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


class ContextAwareActivityRuntime:
    """Scripted plumbing probe, not a counterfactual test of model capability."""

    model = "context-aware-test"

    def __init__(self):
        self.received = []

    async def send(self, message):
        context = json.loads(message.split("本次处境（内容材料）：\n", 1)[1])
        self.received.append(context)
        if not context["formations"]:
            response = {
                "message": "先形成一个暂定读法，再用构造接触它。",
                "formation_updates": [{"id": "form-a", "text": "这段材料可能需要从变化中理解。"}],
                "move": {"text": "先构造一个最小例子。", "formation_refs": ["form-a"],
                          "operations": [{"type": "create", "title": "最小例子", "format": "text",
                                           "body": "构造出的新材料。"}]},
            }
        else:
            response = {
                "message": "真实构造使原来的读法需要重新检查。",
                "formation_updates": [
                    {"id": "form-a", "text": "原读法需要重新检查。", "status": "changed"},
                    {"id": "form-b", "text": "构造结果打开了另一种读法。"},
                ],
                "pause": {"reason": "保留两种读法，等待下一次接触"},
            }
        return RuntimeReply(thread_id="context-aware", turn_id=f"turn-{len(self.received)}",
                             text=json.dumps(response, ensure_ascii=False))


class ReturningConditionRuntime:
    """Two-visit plumbing probe: the second reply changes only if prior state and new fact both arrive."""

    model = "returning-condition-test"

    def __init__(self):
        self.received = []

    async def send(self, message):
        context = json.loads(message.split("本次处境（内容材料）：\n", 1)[1])
        self.received.append(context)
        if len(self.received) == 1:
            response = {
                "message": "先保留一个暂定解释，等新的事实。",
                "formation_updates": [{
                    "id": "return-f1", "text": "窗还亮着，暂时推测屋内有人。",
                    "refs": [{"document": "source", "version": 1}],
                }],
                "tension_updates": [{
                    "id": "return-t1", "text": "还不知道灯是否可能自动开启。",
                    "status": "open",
                }],
                "pause": {"reason": "等待能改变当前条件的新事实"},
            }
        else:
            has_prior = (
                any(item.get("id") == "return-f1" for item in context["formations"])
                and any(item.get("id") == "return-t1" for item in context["tensions"])
            )
            has_changed_fact = "无人值守的定时灯" in context["user_input"]["content"]
            if has_prior and has_changed_fact:
                response = {
                    "message": "新事实削弱了先前推测，下一步应改查定时规则。",
                    "formation_updates": [{
                        "id": "return-f1", "status": "dormant",
                        "text": "定时灯使“亮着=有人”失去当前依据。",
                    }],
                    "tension_updates": [{"id": "return-t1", "status": "resolved"}],
                    "move": {
                        "text": "改查灯的定时规则，而不是继续追问屋内是否有人。",
                        "formation_refs": ["return-f1"], "tension_refs": ["return-t1"],
                    },
                }
            else:
                response = {"pause": {"reason": "没有同时收到旧状态与改变条件的新事实"}}
        return RuntimeReply(thread_id="returning-condition",
                            turn_id=f"turn-{len(self.received)}",
                            text=json.dumps(response, ensure_ascii=False))


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

    async def setup_activity(self, responses, *, search_fn=None):
        runtime = ScriptedActivityRuntime(responses)
        app = create_app(self.config, participants={"test": Participant("test", "测试模型", "脚本替身", runtime)},
                         search_fn=search_fn)
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

    async def test_activity_list_puts_most_recently_revised_activity_first(self):
        app, client, runtime, first = await self.setup_activity([])
        second = (await client.post(
            f"/api/projects/{first['project_id']}/activities", json={"title": "后来创建的活动"}
        )).json()["activity"]
        initial = (await client.get(f"/api/projects/{first['project_id']}/activities")).json()["activities"]
        self.assertEqual(second["id"], initial[0]["id"])

        revised = await client.post(f"/api/activities/{first['id']}/changes", json={
            "revision": first["revision"],
            "operations": [{"action": "set_focus", "text": "回来继续这一件事"}],
        })
        self.assertEqual(200, revised.status_code, revised.text)
        resumed = (await client.get(f"/api/projects/{first['project_id']}/activities")).json()["activities"]
        self.assertEqual(first["id"], resumed[0]["id"])
        self.assertTrue(resumed[0]["created_at"])

    async def test_cross_activity_construction_keeps_dependencies_and_can_be_revised(self):
        app, client, runtime, source = await self.setup_activity([])
        source = (await client.post(f"/api/activities/{source['id']}/changes", json={"revision": source["revision"], "operations": [
            {"action": "add_document", "id": "n", "title": "数字", "format": "json", "body": 7},
            {"action": "add_document", "id": "helper", "title": "构造", "format": "json",
             "body": {"op": "calculate", "operator": "*", "left": {"op": "read", "document": "n"},
                      "right": {"op": "literal", "value": 3}}},
            {"action": "add_document", "id": "method", "title": "入口", "format": "json",
             "body": {"op": "call", "document": "helper"}},
            {"action": "adopt", "document": "method"}]})).json()["activity"]
        target = (await client.post(f"/api/projects/{source['project_id']}/activities", json={})).json()["activity"]
        prefix = f"/api/activities/{target['id']}"
        request = {"revision": target["revision"], "request_id": "transfer-1", "source_activity": source["id"],
                   "source_revision": source["revision"], "document": "method", "kind": "construction", "note": "只在此数值构造中用过"}
        response = await client.post(prefix + "/imports/from-activity", json=request)
        self.assertEqual(200, response.status_code, response.text)
        imported = response.json()
        self.assertEqual("imported", imported["run"]["status"], imported)
        result = imported["run"]["events"][0]["results"][0]
        self.assertIsNone(imported["activity"]["state"]["adopted"])
        self.assertEqual(3, len(result["manifest"]["origins"]))
        self.assertEqual(source, app.state.activity_store.get(source["id"]))
        count = len(app.state.history.read_all())
        retry = (await client.post(prefix + "/imports/from-activity", json=request)).json()
        self.assertEqual(imported["run"]["id"], retry["run"]["id"])
        self.assertEqual(count, len(app.state.history.read_all()))
        adopted = (await client.post(prefix + "/changes", json={"revision": imported["activity"]["revision"],
            "operations": [{"action": "adopt", "document": result["document"]}]})).json()["activity"]
        self.assertEqual(21, (await client.get(prefix + "/preview")).json()["value"])
        # Source edits cannot silently alter the imported version.
        await client.post(f"/api/activities/{source['id']}/changes", json={"revision": source["revision"], "operations": [
            {"action": "revise_document", "document": "n", "body": 100}]})
        self.assertEqual(21, (await client.get(prefix + "/preview")).json()["value"])
        revised = (await client.post(prefix + "/changes", json={"revision": adopted["revision"], "operations": [
            {"action": "revise_document", "document": result["document"], "body": {"op": "literal", "value": 8}},
            {"action": "adopt", "document": result["document"]}]})).json()["activity"]
        self.assertEqual(8, (await client.get(prefix + "/preview")).json()["value"])
        self.assertEqual(2, len(revised["state"]["documents"][result["document"]]["versions"]))
        self.assertEqual(source["state"]["documents"]["method"], app.state.activity_store.get(source["id"])["state"]["documents"]["method"])

    async def test_experience_candidates_require_explicit_browse_and_do_not_auto_adopt(self):
        app, client, runtime, source = await self.setup_activity([])
        source = (await client.post(f"/api/activities/{source['id']}/changes", json={
            "revision": source["revision"], "operations": [
                {"action": "add_document", "id": "method", "title": "旧活动中的构造",
                 "format": "json", "body": {"op": "literal", "value": "可复用的做法"}},
                {"action": "adopt", "document": "method"},
            ]})).json()["activity"]
        target = (await client.post(
            f"/api/projects/{source['project_id']}/activities", json={"title": "新处境"}
        )).json()["activity"]
        prefix = f"/api/activities/{target['id']}"

        catalog = (await client.get(prefix + "/experience-candidates")).json()
        self.assertFalse(catalog["automatic_adoption"])
        candidate = next(item for item in catalog["candidates"] if item["document"] == "method")
        self.assertEqual(source["id"], candidate["source_activity"])
        self.assertEqual("construction", candidate["kind"])
        self.assertEqual("unverified_candidate", candidate["applicability"])
        self.assertEqual({}, target["state"]["documents"])

        runtime.responses = [
            {"move": {"text": "先看看过去实际用过什么，不预设适用。",
                      "operations": [{"type": "browse_experience", "limit": 10}]}},
            {"pause": {"reason": "只浏览候选，暂不带入"}},
        ]
        continued = await client.post(prefix + "/continue", json={
            "revision": target["revision"], "request_id": "browse-past-use", "target": "test"})
        self.assertEqual(200, continued.status_code, continued.text)
        payload = continued.json()
        result = payload["run"]["events"][0]["results"][0]
        self.assertEqual(1, result["candidate_count"])
        self.assertIn(result["document"], payload["activity"]["state"]["documents"])
        self.assertIsNone(payload["activity"]["state"].get("adopted"))
        self.assertNotIn("method", payload["activity"]["state"]["documents"])
        self.assertEqual(1, runtime.received[1]["operation_results"][0]["candidate_count"])

    async def test_occurrence_import_rejects_other_project(self):
        app, client, runtime, activity = await self.setup_activity([])
        before_documents = json.loads(json.dumps(activity["state"]["documents"], ensure_ascii=False))
        other_org = (await client.post("/api/orgs", json={"name": "另一个组织"})).json()["organization"]
        other_project = (await client.post(
            f"/api/orgs/{other_org['id']}/projects", json={"title": "另一个项目"})).json()["project"]
        foreign = app.state.history.append_message(
            author="外部作者", content="不属于当前项目的原话", project_id=other_project["id"])
        response = await client.post(f"/api/activities/{activity['id']}/imports", json={
            "revision": activity["revision"], "occurrence_id": foreign["id"], "title": "不应带入"})
        self.assertEqual(422, response.status_code, response.text)
        self.assertEqual(before_documents, app.state.activity_store.get(activity["id"])["state"]["documents"])

    async def test_transfer_rejects_other_project_and_stale_target_without_copying(self):
        app, client, runtime, source = await self.setup_activity([])
        org = (await client.post("/api/orgs", json={"name": "另一个项目环境"})).json()["organization"]
        project = (await client.post(f"/api/orgs/{org['id']}/projects", json={"title": "另一个项目"})).json()["project"]
        target = (await client.post(f"/api/projects/{project['id']}/activities", json={})).json()["activity"]
        count = len(app.state.history.read_all())
        request = {"revision": 1, "request_id": "wrong-project", "source_activity": source["id"],
                   "source_revision": source["revision"], "document": "source"}
        payload = (await client.post(f"/api/activities/{target['id']}/imports/from-activity", json=request)).json()
        self.assertEqual("failed", payload["run"]["status"])
        self.assertEqual({}, payload["activity"]["state"]["documents"])
        self.assertEqual(count, len(app.state.history.read_all()))
        request.update(revision=1, request_id="stale-target")
        payload = (await client.post(f"/api/activities/{source['id']}/imports/from-activity", json=request)).json()
        self.assertEqual("conflict", payload["run"]["status"])
        self.assertEqual(count, len(app.state.history.read_all()))

    async def test_computation_result_is_reusable_without_model_transcription(self):
        expression = {"op": "calculate", "operator": "*", "left": {"op": "read", "document": "number"},
                      "right": {"op": "literal", "value": 3}}
        app, client, runtime, activity = await self.setup_activity([
            {"move": {"operations": [{"type": "evaluate", "expression": expression}]}},
            {"pause": {"reason": "结果先保留"}},
        ])
        prefix = f"/api/activities/{activity['id']}"
        response = await client.post(prefix + "/changes", json={"revision": activity["revision"], "operations": [
            {"action": "add_document", "id": "number", "title": "输入", "format": "json", "body": 7}]})
        activity = response.json()["activity"]
        raw_before = self.config.history_path.read_bytes()
        request = {"revision": activity["revision"], "request_id": "calculate-once", "target": "test"}
        response = await client.post(prefix + "/continue", json=request)
        self.assertEqual(200, response.status_code, response.text)
        result = response.json()
        computed = result["run"]["events"][0]["results"][0]
        doc_id = computed["document"]
        doc = result["activity"]["state"]["documents"][doc_id]
        self.assertTrue(doc["immutable"])
        self.assertEqual(21, doc["versions"][0]["body"])
        self.assertEqual(doc_id, runtime.received[1]["operation_results"][0]["document"])
        raw = app.state.history.get_by_id(computed["source_occurrence_id"])
        self.assertEqual(expression, raw["evaluation"]["expression"])
        self.assertEqual(activity["revision"], raw["evaluation"]["input_revision"])
        self.assertEqual([{"document": "number", "version": 1}], raw["evaluation"]["reads"])
        self.assertTrue(self.config.history_path.read_bytes().startswith(raw_before))
        count = len(app.state.history.read_all())
        repeated = await client.post(prefix + "/continue", json=request)
        self.assertEqual(result["run"]["id"], repeated.json()["run"]["id"])
        self.assertEqual(count, len(app.state.history.read_all()))
        # Changing the original input must not rewrite or silently recompute the result.
        response = await client.post(prefix + "/changes", json={"revision": result["activity"]["revision"], "operations": [
            {"action": "revise_document", "document": "number", "body": 100},
            {"action": "add_document", "id": "result-view", "title": "面对计算结果", "format": "json",
             "body": {"op": "read", "document": doc_id}},
            {"action": "adopt", "document": "result-view"}]})
        self.assertEqual(200, response.status_code, response.text)
        self.assertEqual(21, (await client.get(prefix + "/preview")).json()["value"])
        current = response.json()["activity"]
        runtime.responses = [{"move": {"operations": [{"type": "evaluate", "expression": {
            "op": "calculate", "operator": "+", "left": {"op": "read", "document": doc_id},
            "right": {"op": "literal", "value": 1}}}]}}, {"message": "第二次构造已完成"}]
        second = (await client.post(prefix + "/continue", json={"revision": current["revision"],
            "request_id": "reuse-calculation", "target": "test"})).json()
        self.assertEqual(22, second["run"]["events"][0]["results"][0]["evaluation"]["value"])
        self.assertEqual(21, second["activity"]["state"]["documents"][doc_id]["versions"][0]["body"])

    async def test_failed_computation_does_not_create_result_material(self):
        app, client, runtime, activity = await self.setup_activity([
            {"move": {"operations": [{"type": "evaluate", "expression": {"op": "calculate", "operator": "/",
                "left": {"op": "literal", "value": 1}, "right": {"op": "literal", "value": 0}}}]}},
            {"pause": {"reason": "计算失败"}},
        ])
        response = await client.post(f"/api/activities/{activity['id']}/continue", json={
            "revision": activity["revision"], "request_id": "failed-calculation", "target": "test"})
        result = response.json()
        self.assertEqual("failed", result["run"]["events"][0]["results"][0]["status"])
        self.assertEqual(activity["state"]["documents"], result["activity"]["state"]["documents"])
        self.assertFalse(any(item.get("evaluation") for item in app.state.history.read_all()))

    async def test_adoption_can_focus_input_then_explicit_read_reopens_it(self):
        app, client, runtime, activity = await self.setup_activity([
            {"move": {"text": "先看开头", "operations": [{"type": "change", "operations": [
                {"action": "add_document", "id": "view", "title": "局部阅读", "format": "json",
                 "body": {"op": "slice", "input": {"op": "read", "document": "source"}, "end": 1}},
                {"action": "adopt", "document": "view"}]}]}},
            {"move": {"text": "回看原句", "operations": [{"type": "read", "document": "source", "version": 1}]}},
            {"pause": {"reason": "保留原句"}},
        ])
        app.state.activity_service.max_calls = 3
        response = await client.post(f"/api/activities/{activity['id']}/continue", json={
            "revision": activity["revision"], "request_id": "focus-and-reopen", "target": "test"})
        self.assertEqual(200, response.status_code, response.text)
        self.assertEqual("paused", response.json()["run"]["status"])
        self.assertEqual("窗", runtime.received[1]["compiled"]["text"])
        self.assertNotIn("窗还亮着。", json.dumps(runtime.received[1], ensure_ascii=False))
        self.assertEqual("窗还亮着。", runtime.received[2]["operation_results"][0]["document"]["body"])
        self.assertEqual("窗", runtime.received[2]["compiled"]["text"])
        snapshots = app.state.activity_store.list_context_snapshots(activity["id"])
        self.assertEqual(3, len(snapshots))

    async def test_raw_occurrence_tail_returns_without_changing_acquisition(self):
        app, client, runtime, activity = await self.setup_activity([])
        content = "旧解释关注的部分。" * 1000 + "末尾：我其实不是这个意思。"
        original = app.state.history.append_message(author="材料作者", project_id=activity["project_id"], content=content)
        prefix_bytes = self.config.history_path.read_bytes()
        runtime.responses = [{"move": {"text": "回看原话未读部分", "operations": [
            {"type": "read_occurrence", "occurrence_id": original["id"], "start": 6000}]}},
            {"pause": {"reason": "先保留新读到的内容"}}]
        request = {"revision": activity["revision"], "request_id": "read-original-tail", "target": "test"}
        response = await client.post(f"/api/activities/{activity['id']}/continue", json=request)
        self.assertEqual(200, response.status_code, response.text)
        result = response.json()
        self.assertEqual("paused", result["run"]["status"], result)
        encountered = runtime.received[1]["operation_results"][0]
        self.assertEqual(content[6000:12000], encountered["acquisition"]["content"])
        self.assertEqual(original["id"], encountered["acquisition"]["occurrence_id"])
        self.assertTrue(result["activity"]["state"]["documents"][encountered["document"]]["immutable"])
        self.assertEqual(activity["state"].get("source_adopted"), result["activity"]["state"].get("source_adopted"))
        self.assertTrue(self.config.history_path.read_bytes().startswith(prefix_bytes))
        after = self.config.history_path.read_bytes()
        retried = await client.post(f"/api/activities/{activity['id']}/continue", json=request)
        self.assertEqual(result["run"]["id"], retried.json()["run"]["id"])
        self.assertEqual(after, self.config.history_path.read_bytes())

    def test_occurrence_read_pages_scope_and_invalid_ranges(self):
        from activity_acquisition import read_project_occurrence
        from activity_program import ProgramError
        content = "前文🌱" * 3500 + "末尾"
        records = [{"id": "original", "project_id": "p", "author": "作者", "content": content}]
        pages, start = [], 0
        while start is not None:
            page = read_project_occurrence(records, "p", "original", start=start)
            pages.append(page["content"])
            start = page["next_start"]
        self.assertEqual(content, "".join(pages))
        for kwargs in ({"start": -1}, {"start": True}, {"length": 6001}, {"length": 0}, {"start": len(content) + 1}):
            with self.subTest(kwargs=kwargs), self.assertRaises(ProgramError):
                read_project_occurrence(records, "p", "original", **kwargs)
        with self.assertRaises(ProgramError):
            read_project_occurrence(records, "different-project", "original")

    async def test_external_retrieval_becomes_immutable_activity_material(self):
        async def fake_search(query):
            return [{"title": f"来源：{query}", "url": "https://example.test/source",
                     "snippet": "候选摘要", "provider": "test", "query": query}]

        app, client, runtime, activity = await self.setup_activity([{
            "message": "先从两个方向取得外部材料。",
            "move": {"text": "分别探入制度背景与实际路径。", "operations": [{
                "type": "retrieve", "fetch_content": False, "queries": [
                    {"query": "数学学习制度背景", "axis": "背景"},
                    {"query": "数学学习可用路径", "axis": "路径"},
                ]
            }]}
        }, {"message": "材料已经进入当前活动，先停在这里。",
            "pause": {"reason": "等待重新理解材料"}}], search_fn=fake_search)
        response = await client.post(f"/api/activities/{activity['id']}/continue", json={
            "revision": activity["revision"], "request_id": "retrieve-1", "target": "test"})
        self.assertEqual(200, response.status_code, response.text)
        result = response.json()
        self.assertEqual("paused", result["run"]["status"], result)
        operation = result["run"]["events"][0]["results"][0]
        self.assertEqual("ok", operation["status"])
        self.assertEqual(2, operation["queries"])
        document = result["activity"]["state"]["documents"][operation["document"]]
        self.assertTrue(document["immutable"])
        payload = document["versions"][0]["body"]
        self.assertEqual("external_retrieval", payload["type"])
        self.assertEqual(2, len(payload["searches"]))
        self.assertIn("候选摘要", result["run"]["events"][1]["context"]["recent_encounters"][0]["material"])
        self.assertTrue(any(item.get("activity_external_retrieval") for item in app.state.history.read_all()))

    async def test_project_occurrence_can_start_activity_without_becoming_a_project_fact(self):
        app, client, runtime, activity = await self.setup_activity([])
        project_id = activity["project_id"]
        source = app.state.history.append_message(project_id=project_id, author="项目成员", content="从这里继续理解。")
        response = await client.post(f"/api/projects/{project_id}/activities/from-occurrence", json={
            "occurrence_id": source["id"], "title": "从项目发言继续"})
        self.assertEqual(200, response.status_code, response.text)
        created = response.json()["activity"]
        self.assertNotEqual(activity["id"], created["id"])
        document = next(iter(created["state"]["documents"].values()))
        self.assertEqual("从这里继续理解。", document["versions"][0]["body"])
        self.assertTrue(document["immutable"])
        self.assertEqual(source["id"], document["versions"][0]["occurrence_id"])
        self.assertEqual([], [item for item in app.state.history.read_all()
                              if item.get("activity_id") == created["id"] and item.get("handoff_result")])

    async def test_publish_requires_explicit_request_and_is_idempotent(self):
        app, client, runtime, activity = await self.setup_activity([])
        payload = {"request_id": "publish-1", "kind": "work_item", "title": "继续验证",
                   "content": "把这次构造与用户反馈放回项目中继续验证。",
                   "source_document_id": "source"}
        first = await client.post(f"/api/activities/{activity['id']}/publish", json=payload)
        self.assertEqual(200, first.status_code, first.text)
        item = first.json()["published"]["object"]
        self.assertEqual("open", item["status"])
        self.assertIn(f"activity:{activity['id']}", item["source_refs"])
        self.assertIn("document:source@1", item["source_refs"])
        second = await client.post(f"/api/activities/{activity['id']}/publish", json=payload)
        self.assertEqual(200, second.status_code, second.text)
        self.assertEqual(item["id"], second.json()["published"]["object"]["id"])
        self.assertEqual(1, len((await client.get(f"/api/projects/{activity['project_id']}/work-items")).json()["work_items"]))

    async def test_publish_message_is_explicit_and_keeps_activity_provenance(self):
        app, client, runtime, activity = await self.setup_activity([])
        response = await client.post(f"/api/activities/{activity['id']}/publish", json={
            "request_id": "publish-message-1", "kind": "message",
            "content": "这是一份经过确认的活动阶段性表达。", "source_document_id": "source"})
        self.assertEqual(200, response.status_code, response.text)
        record = response.json()["published"]["object"]
        self.assertEqual(activity["project_id"], record["project_id"])
        self.assertEqual(activity["id"], record["activity_id"])
        self.assertEqual("publish-message-1", record["activity_publish"]["request_id"])

    async def test_publish_decision_and_work_item_are_project_visible_with_sources(self):
        app, client, runtime, activity = await self.setup_activity([])
        base = {"source_document_id": "source"}
        decision = await client.post(f"/api/activities/{activity['id']}/publish", json={
            **base, "request_id": "publish-decision-1", "kind": "decision",
            "content": "先保留数学学习的开放路径。"})
        item = await client.post(f"/api/activities/{activity['id']}/publish", json={
            **base, "request_id": "publish-item-1", "kind": "work_item",
            "title": "继续构造例子", "content": "用二维材料继续检验理解。"})
        self.assertEqual(200, decision.status_code, decision.text)
        self.assertEqual(200, item.status_code, item.text)
        decisions = (await client.get(f"/api/projects/{activity['project_id']}/decisions")).json()["decisions"]
        work_items = (await client.get(f"/api/projects/{activity['project_id']}/work-items")).json()["work_items"]
        self.assertEqual("proposed", decisions[0]["status"])
        self.assertIn(f"activity:{activity['id']}", decisions[0]["source_refs"])
        self.assertIn("document:source@1", work_items[0]["source_refs"])

    async def test_creative_move_can_pause_without_forcing_a_plan_or_result(self):
        app, client, runtime, activity = await self.setup_activity([{
            "message": "这几个片段先放在一起看看。",
            "formation_updates": [],
            "move": {"text": "先续写这一幕，不预先解释它要成为什么。", "refs": [], "operations": []},
        }])
        response = await client.post(f"/api/activities/{activity['id']}/continue", json={
            "revision": activity["revision"], "request_id": "creative-pause-1",
            "content": "洗脸、抬头、天桥、风。先写，不制定计划。", "target": "test"})
        self.assertEqual(200, response.status_code, response.text)
        result = response.json()
        self.assertEqual("paused", result["run"]["status"])
        self.assertEqual("move_proposed", result["run"]["events"][-1]["status"])
        self.assertEqual([], result["run"]["events"][-1]["move"]["operations"])
        self.assertEqual(1, len(result["activity"]["state"]["documents"]))

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

    async def test_open_envelope_keeps_multiple_formations_and_proposes_empty_move(self):
        app, client, runtime, activity = await self.setup_activity([{
            "message": "先把两种读法放在一起看。",
            "formation_updates": [{"text": "读法 A"}, {"text": "读法 B"}],
            "move": {"text": "并置两段材料", "refs": ["source@1"],
                      "formation_refs": ["formation-a"], "tension_refs": ["tension-a"]},
        }])
        result = (await client.post(f"/api/activities/{activity['id']}/continue", json={
            "revision": activity["revision"], "request_id": "open-envelope", "target": "test"})).json()
        self.assertEqual("paused", result["run"]["status"], result)
        event = result["run"]["events"][0]
        self.assertEqual("move_proposed", event["status"])
        self.assertIsNone(event["runtime"]["actual_model"])  # No response identity supplied.
        self.assertEqual("runtime_managed_history_not_captured", event["runtime"]["input_scope"])
        self.assertEqual("test-script", event["runtime"]["requested_model"])
        self.assertEqual(2, len(event["formation_updates"]))
        self.assertEqual("并置两段材料", event["move"]["text"])
        self.assertEqual(["formation-a"], event["move"]["formation_refs"])
        self.assertEqual(["tension-a"], event["move"]["tension_refs"])
        move = app.state.activity_store.get_move(event["move_id"])
        self.assertEqual([], move["execution"])
        self.assertEqual("并置两段材料", move["intent"])
        self.assertEqual(["formation-a"], move["state"]["formation_refs"])
        snapshots = (await client.get(f"/api/activities/{activity['id']}/contexts")).json()["snapshots"]
        self.assertEqual(1, len(snapshots))
        detail = (await client.get(f"/api/activities/{activity['id']}/contexts/{snapshots[0]['id']}")).json()["snapshot"]
        self.assertIn("本次处境（内容材料）：", detail["rendered_prompt"])
        self.assertEqual(snapshots[0]["rendered_prompt_hash"], detail["rendered_prompt_hash"])

        models = (await client.get("/api/models")).json()
        profile = next(item["runtime_profile"] for item in models["participants"] if item["id"] == "test")
        self.assertEqual("test-script", profile["model"])
        self.assertNotIn("api_key", profile)

    async def test_formations_reappear_in_a_later_activity_context(self):
        app, client, runtime, activity = await self.setup_activity([
            {"message": "先保留两种读法", "formation_updates": [{"id": "form-a", "text": "读法 A"}, {"id": "form-b", "text": "读法 B"}],
             "tension_updates": [{"id": "tension-a", "text": "两种读法尚未能互相解释", "status": "open"}]},
            {"message": "现在继续面对它们。"},
        ])
        first = (await client.post(f"/api/activities/{activity['id']}/continue", json={
            "revision": activity["revision"], "request_id": "forms-1", "target": "test"})).json()
        self.assertIsNone(first["activity"]["state"].get("situation"))
        second = (await client.post(f"/api/activities/{activity['id']}/continue", json={
            "revision": first["activity"]["revision"], "request_id": "forms-2", "target": "test"})).json()
        self.assertEqual("paused", second["run"]["status"])
        texts = [item["text"] for item in runtime.received[1]["formations"]]
        self.assertIn("读法 A", texts)
        self.assertIn("读法 B", texts)
        self.assertIn("form-a", [item["id"] for item in runtime.received[1]["formations"]])
        tensions = [item["text"] for item in runtime.received[1]["tensions"]]
        self.assertIn("两种读法尚未能互相解释", tensions)
        self.assertIn("tension-a", [item["id"] for item in runtime.received[1]["tensions"]])
        self.assertNotIn("tensions", runtime.received[1]["cognitive_conditions"])

    async def test_transport_repairs_are_visible_on_run_event(self):
        app, client, runtime, activity = await self.setup_activity([
            '{"formation\\_updates":\\[{"text":"一个读法"}\\],'
            '"tension\\_updates":\\[{"text":"还没安顿","status":"active"}\\],'
            '"pause":{"text":"先暂停"}}'
        ])
        result = (await client.post(f"/api/activities/{activity['id']}/continue", json={
            "revision": activity["revision"], "request_id": "transport-repair", "target": "test",
        })).json()
        self.assertEqual("paused", result["run"]["status"], result)
        event = result["run"]["events"][0]
        self.assertEqual(
            ["markdown_escaped_underscore", "markdown_escaped_square_brackets",
             "tension_status_active_to_open", "pause_text_to_reason"],
            event["transport_repairs"],
        )
        self.assertEqual("open", event["tension_updates"][0]["status"])
        self.assertEqual("先暂停", event["pause"]["reason"])

    async def test_second_visit_new_fact_revises_prior_state_and_changes_next_move(self):
        runtime = ReturningConditionRuntime()
        app = create_app(self.config, participants={
            "test": Participant("test", "条件变化探针", "脚本替身", runtime)})
        client = AsyncClient(transport=ASGITransport(app=app), base_url="http://test")
        self.addAsyncCleanup(client.aclose)
        org = (await client.post("/api/orgs", json={"name": "第二次进入测试"})).json()["organization"]
        project = (await client.post(f"/api/orgs/{org['id']}/projects", json={"title": "条件会变化"})).json()["project"]
        activity = (await client.post(f"/api/projects/{project['id']}/activities", json={})).json()["activity"]
        activity = (await client.post(f"/api/activities/{activity['id']}/changes", json={
            "revision": activity["revision"], "operations": [{
                "action": "add_document", "id": "source", "title": "最初看到的事实",
                "format": "text", "body": "窗还亮着。", "immutable": True,
            }],
        })).json()["activity"]
        prefix = f"/api/activities/{activity['id']}"

        first = (await client.post(prefix + "/continue", json={
            "revision": activity["revision"], "request_id": "first-visit",
            "content": "先看看这意味着什么。", "target": "test",
        })).json()
        self.assertEqual("paused", first["run"]["status"], first)
        first_event = json.loads(json.dumps(first["run"]["events"][0], ensure_ascii=False))
        first_view = (await client.get(prefix)).json()["activity_view"]
        self.assertEqual("return-f1", first_view["formations"]["active"][0]["id"])
        self.assertEqual("return-t1", first_view["tensions"]["open"][0]["id"])

        # Simulate coming back through a fresh service instance over the same persisted Activity/runs.
        from activity_service import ActivityService
        app.state.activity_service = ActivityService(app.state.activity_store,
                                                     app.state.activity_service.conversation)
        current = app.state.activity_store.get(activity["id"])
        second = await app.state.activity_service.continue_activity(
            activity["id"], revision=current["revision"], request_id="second-visit",
            content="新事实：这是无人值守的定时灯，屋内没人。", target="test")
        self.assertEqual("paused", second["status"], second)

        second_context = runtime.received[1]
        self.assertEqual("return-f1", second_context["formations"][0]["id"])
        self.assertEqual("return-t1", second_context["tensions"][0]["id"])
        self.assertIn("无人值守的定时灯", second_context["user_input"]["content"])
        event = second["events"][0]
        self.assertEqual("move_proposed", event["status"])
        self.assertEqual("改查灯的定时规则，而不是继续追问屋内是否有人。", event["move"]["text"])

        final_view = (await client.get(prefix)).json()["activity_view"]
        self.assertEqual([], final_view["formations"]["active"])
        dormant = final_view["formations"]["dormant"][0]
        self.assertEqual("return-f1", dormant["id"])
        self.assertIn("失去当前依据", dormant["text"])
        self.assertEqual([], final_view["tensions"]["open"])
        self.assertEqual("return-t1", final_view["tensions"]["resolved"][0]["id"])
        saved_first = next(item for item in app.state.activity_store.runs(activity["id"])
                           if item["id"] == first["run"]["id"])
        self.assertEqual(first_event, saved_first["events"][0])

    async def test_tension_revision_omissions_survive_parser_event_and_activity_view(self):
        app, client, runtime, activity = await self.setup_activity([
            {"tension_updates": [{"id": "t1", "text": "这里还没解释",
                                  "refs": [{"document": "source", "version": 1}]}]},
            {"tension_updates": [{"id": "t1", "status": "resolved"}]},
            {"tension_updates": [{"id": "t1", "text": "后来把措辞改清楚"}]},
        ])
        prefix = f"/api/activities/{activity['id']}"
        for index in range(3):
            current = app.state.activity_store.get(activity["id"])
            response = await client.post(prefix + "/continue", json={
                "revision": current["revision"], "request_id": f"tension-revision-{index}", "target": "test"})
            self.assertEqual(200, response.status_code, response.text)

        runs = app.state.activity_store.runs(activity["id"])
        self.assertEqual({"id": "t1", "status": "resolved"}, runs[1]["events"][0]["tension_updates"][0])
        self.assertEqual({"id": "t1", "text": "后来把措辞改清楚"}, runs[2]["events"][0]["tension_updates"][0])

        detail = (await client.get(prefix)).json()
        self.assertEqual([], detail["activity_view"]["tensions"]["open"])
        resolved = detail["activity_view"]["tensions"]["resolved"][0]
        self.assertEqual("resolved", resolved["status"])
        self.assertEqual("后来把措辞改清楚", resolved["text"])
        self.assertEqual([{"document": "source", "version": 1}], resolved["refs"])

    async def test_transport_input_is_saved_before_request_and_available_after_failure(self):
        import hashlib
        import httpx
        from unittest.mock import patch
        from deepseek_runtime import DeepSeekRuntimeAdapter, DeepSeekStateStore, DeepSeekConversationState

        app, client, _, activity = await self.setup_activity([])
        store = DeepSeekStateStore(Path(self.directory.name) / "separate-chat.json")
        store.save(DeepSeekConversationState("ordinary-chat", [{"role": "user", "content": "hidden-old-chat"}]))
        runtime = DeepSeekRuntimeAdapter(api_key="credential-sentinel", state_store=store,
                                         developer_instructions="fixed participant instructions")
        participant = Participant("test", "测试模型", "HTTP 替身", runtime)
        app.state.activity_service.conversation.participants["test"] = participant
        original = store.path.read_bytes()
        outcomes = [200, 400]
        async def post(url, **kwargs):
            latest = app.state.activity_store.runs(activity["id"])[-1]
            recorded = latest["events"][-1]["runtime_requests"][-1]
            self.assertEqual(recorded["payload"], kwargs["json"])
            self.assertNotIn("hidden-old-chat", json.dumps(recorded))
            self.assertNotIn("credential-sentinel", json.dumps(recorded))
            status = outcomes.pop(0)
            return httpx.Response(status, json={"model": "reported-runtime",
                "choices": [{"message": {"content": '{"message":"暂时保持开放。"}'}}]})

        for index in range(2):
            with patch("httpx.AsyncClient.post", side_effect=post):
                # Call service directly so the transport mock does not replace ASGI routing.
                run = await app.state.activity_service.continue_activity(activity["id"],
                    revision=activity["revision"], request_id=f"wire-{index}", content="继续", target="test")
            self.assertEqual("paused" if index == 0 else "failed", run["status"])
            event = run["events"][0]
            detail = (await client.get(f"/api/activities/{activity['id']}/contexts/{event['context_snapshot_id']}")).json()
            capture = dict(detail["runtime_requests"][0])
            fingerprint = capture.pop("request_hash")
            capture.pop("boundary")
            expected = hashlib.sha256(json.dumps(capture, ensure_ascii=False, sort_keys=True,
                                                  allow_nan=False).encode("utf-8")).hexdigest()
            self.assertEqual(expected, fingerprint)
            self.assertEqual(event["context"], detail["snapshot"]["context"])
            self.assertIsNone(detail["snapshot"]["context"]["runtime"]["actual_model"])
            self.assertEqual(original, store.path.read_bytes())
            if index == 0:
                self.assertEqual("reported-runtime", event["runtime"]["actual_model"])

    async def test_provider_failure_does_not_archive_echoed_credentials(self):
        from codex_runtime import RuntimeUnavailableError
        app, client, _, activity = await self.setup_activity([
            RuntimeUnavailableError("返回 HTTP 401 https://api.test?key=credential-sentinel")])
        result = (await client.post(f"/api/activities/{activity['id']}/continue", json={
            "revision": activity["revision"], "request_id": "secret-error", "target": "test"})).json()
        self.assertEqual(401, result["run"]["http_status"])
        self.assertNotIn("credential-sentinel", json.dumps(result))
        self.assertNotIn("credential-sentinel", json.dumps(app.state.activity_store.runs(activity["id"])))

    async def test_scripted_construction_returns_material_and_context(self):
        runtime = ContextAwareActivityRuntime()
        app = create_app(self.config, participants={
            "test": Participant("test", "上下文探针", "脚本替身", runtime)})
        client = AsyncClient(transport=ASGITransport(app), base_url="http://test")
        self.addAsyncCleanup(client.aclose)
        org = (await client.post("/api/orgs", json={"name": "程序接续测试"})).json()["organization"]
        project = (await client.post(f"/api/orgs/{org['id']}/projects", json={"title": "条件改变"})).json()["project"]
        activity = (await client.post(f"/api/projects/{project['id']}/activities", json={})).json()["activity"]
        changed = await client.post(f"/api/activities/{activity['id']}/changes", json={
            "revision": 1, "operations": [{"action": "add_document", "id": "source",
                "title": "原始材料", "format": "text", "body": "一个尚未解释的变化。",
                "immutable": True}]})
        activity = changed.json()["activity"]

        result = await client.post(f"/api/activities/{activity['id']}/continue", json={
            "revision": activity["revision"], "request_id": "plumbing-1", "target": "test"})
        self.assertEqual(200, result.status_code, result.text)
        payload = result.json()
        self.assertEqual("paused", payload["run"]["status"], payload)
        self.assertEqual(2, len(runtime.received))

        first_context, second_context = runtime.received
        self.assertEqual([], first_context["formations"])
        self.assertEqual("form-a", second_context["formations"][0]["id"])
        self.assertTrue(second_context["recent_encounters"])
        self.assertNotEqual(first_context["cognitive_conditions"]["condition_fingerprint"],
                            second_context["cognitive_conditions"]["condition_fingerprint"])
        self.assertEqual("构造结果打开了另一种读法。",
                         payload["run"]["events"][1]["formation_updates"][1]["text"])
        self.assertEqual("ok", payload["run"]["events"][0]["results"][0]["status"])
        self.assertEqual("changed", payload["run"]["events"][1]["formation_updates"][0]["status"])

    async def test_current_understanding_view_keeps_parallel_readings_and_independent_note(self):
        app, client, runtime, activity = await self.setup_activity([
            {"formation_updates": [{"id": "a", "text": "同一措辞"}, {"id": "b", "text": "同一措辞"}]},
            {"formation_updates": [{"id": "a", "status": "dormant"}, {"id": "b", "text": "第二个读法修订"}]},
            {"message": "继续面对材料"},
        ])
        prefix = f"/api/activities/{activity['id']}"
        note = {"text": "我独立写下的理解", "refs": []}
        changed = (await client.post(prefix + "/changes", json={"revision": activity["revision"],
            "operations": [{"action": "set_situation", **note}]})).json()["activity"]
        for index in range(3):
            current = app.state.activity_store.get(activity["id"])
            await client.post(prefix + "/continue", json={"revision": current["revision"],
                "request_id": f"view-{index}", "target": "test"})
            view = (await client.get(prefix)).json()
            self.assertEqual(note, view["activity"]["state"]["situation"])
            if index == 0:
                self.assertEqual(["a", "b"], [item["id"] for item in view["understanding"]["active"]])
        projection = view["understanding"]
        self.assertEqual(["b"], [item["id"] for item in projection["active"]])
        self.assertEqual("同一措辞", projection["dormant"][0]["text"])
        self.assertEqual(4, len(projection["history"]))
        self.assertEqual(["b"], [item["id"] for item in view["activity_view"]["formations"]["active"]])
        self.assertEqual(note, view["activity_view"]["independent_situation"])
        self.assertEqual("测试模型", projection["active"][0]["participant"]["name"])
        self.assertEqual(projection["active"][0]["revision_event_ids"], runtime.received[2]["formations"][0]["revision_event_ids"])
        self.assertEqual(note["text"], runtime.received[2]["situation"]["text"])
        historical = (await client.get(prefix, params={"revision": changed["revision"]})).json()
        self.assertIsNone(historical["understanding"])
        self.assertIsNone(historical["activity_view"])

    async def test_dormant_formation_survives_replay_and_can_be_reactivated(self):
        app, client, runtime, activity = await self.setup_activity([
            {"formation_updates": [{"id": "f1", "text": "先按重复理解。",
                                    "refs": [{"document": "source", "version": 1}]}],
             "tension_updates": [{"id": "t1", "text": "还没分清重复。"}]},
            {"formation_updates": [{"id": "f1", "status": "dormant"}],
             "tension_updates": [{"id": "t1", "text": "这个疑问已暂时处理。", "status": "resolved"}]},
            {"message": "在新材料前暂不形成解释。"},
            {"formation_updates": [{"id": "f1", "status": "active", "text": "重新从重复理解。"}]},
            {"message": "继续。"},
        ])
        url = f"/api/activities/{activity['id']}/continue"
        async def advance(index, content=""):
            current = app.state.activity_store.get(activity["id"])
            response = await client.post(url, json={"revision": current["revision"],
                "request_id": f"lifecycle-{index}", "target": "test", "content": content})
            self.assertEqual(200, response.status_code, response.text)
            self.assertEqual("paused", response.json()["run"]["status"], response.text)
            return response.json()

        first = await advance(0)
        await advance(1, "先不要采用之前的解释。")
        # A new service instance must rebuild the same state from stored events.
        from activity_service import ActivityService
        app.state.activity_service = ActivityService(app.state.activity_store,
                                                    app.state.activity_service.conversation)
        third = await app.state.activity_service.continue_activity(
            activity["id"], revision=app.state.activity_store.get(activity["id"])["revision"],
            request_id="lifecycle-2", target="test", content="这次原话里的亮还可能指什么？")
        self.assertEqual("paused", third["status"])
        context = runtime.received[2]
        self.assertEqual([], context["formations"])
        self.assertIsNone(context["situation"])
        self.assertNotIn("formations", context["cognitive_conditions"])
        self.assertEqual([], context["tensions"])
        self.assertEqual("t1", context["resolved_tensions"][0]["id"])
        dormant = context["dormant_formations"][0]
        self.assertEqual("先按重复理解。", dormant["text"])
        self.assertEqual(2, len(dormant["revision_event_ids"]))
        self.assertEqual("窗还亮着。", context["active_materials"]["entries"][0]["body"])
        self.assertIn("还可能指什么", context["user_input"]["content"])
        await advance(3)
        await advance(4)
        restored = runtime.received[4]
        self.assertEqual(["f1"], [item["id"] for item in restored["formations"]])
        self.assertEqual("重新从重复理解。", restored["formations"][0]["text"])
        self.assertEqual("source", restored["formations"][0]["refs"][0]["document"])
        self.assertEqual([], restored["dormant_formations"])
        saved_first = next(item for item in app.state.activity_store.runs(activity["id"])
                           if item["id"] == first["run"]["id"])
        self.assertEqual("先按重复理解。", saved_first["events"][0]["formation_updates"][0]["text"])

    async def test_user_can_directly_suspend_reactivate_and_revise_a_formation(self):
        app, client, runtime, activity = await self.setup_activity([
            {"formation_updates": [{"id": "f-user", "text": "先保留这个读法。",
                                    "refs": [{"document": "source", "version": 1}]}]},
            {"message": "继续面对。"},
        ])
        prefix = f"/api/activities/{activity['id']}"
        first = (await client.post(prefix + "/continue", json={
            "revision": activity["revision"], "request_id": "user-formation-seed", "target": "test"})).json()
        current = first["activity"]
        suspended_request = {"revision": current["revision"], "request_id": "user-suspend-f1",
                             "formation_id": "f-user", "status": "dormant"}
        suspended = await client.post(prefix + "/formations", json=suspended_request)
        self.assertEqual(200, suspended.status_code, suspended.text)
        suspended_payload = suspended.json()
        view = suspended_payload["understanding"]
        self.assertEqual([], view["active"])
        self.assertEqual(["f-user"], [item["id"] for item in view["dormant"]])
        self.assertEqual(["f-user"], [item["id"] for item in
                                      suspended_payload["activity_view"]["formations"]["dormant"]])
        self.assertEqual("小林", view["dormant"][0]["participant"]["name"])
        self.assertEqual("先保留这个读法。", view["dormant"][0]["text"])
        history_count = len(app.state.history.read_all())
        repeated = (await client.post(prefix + "/formations", json=suspended_request)).json()
        self.assertEqual(suspended.json()["run"]["id"], repeated["run"]["id"])
        self.assertEqual(history_count, len(app.state.history.read_all()))

        continued = await client.post(prefix + "/continue", json={
            "revision": current["revision"], "request_id": "after-user-suspend", "target": "test"})
        self.assertEqual(200, continued.status_code, continued.text)
        self.assertEqual([], runtime.received[1]["formations"])
        self.assertEqual("f-user", runtime.received[1]["dormant_formations"][0]["id"])

        revised = await client.post(prefix + "/formations", json={
            "revision": current["revision"], "request_id": "user-revise-f1",
            "formation_id": "f-user", "status": "dormant", "text": "暂缓，但把措辞改清楚。"})
        self.assertEqual(200, revised.status_code, revised.text)
        dormant = revised.json()["understanding"]["dormant"][0]
        self.assertEqual("暂缓，但把措辞改清楚。", dormant["text"])
        self.assertEqual(3, len(dormant["revision_event_ids"]))

        reactivated = await client.post(prefix + "/formations", json={
            "revision": current["revision"], "request_id": "user-reactivate-f1",
            "formation_id": "f-user", "status": "active"})
        self.assertEqual(200, reactivated.status_code, reactivated.text)
        active = reactivated.json()["understanding"]["active"][0]
        self.assertEqual("暂缓，但把措辞改清楚。", active["text"])
        self.assertEqual("active", active["status"])

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
        recent = next(item for item in runtime.received[0]["recent_activity"]
                      if item["id"] == record["id"])
        self.assertEqual("Codex（设计试用）", recent["author"])
        self.assertEqual("测试模型", recent["target"])
        self.assertTrue(recent["occurred_at"])

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

    async def test_independent_browse_reaches_records_outside_adopted_filter(self):
        app, client, runtime, activity = await self.setup_activity([
            {"move": {"operations": [{"type": "browse_history"}]}},
            {"message": "先保留未命中筛选的材料"},
        ])
        activity, first, second = await self.setup_source(app, client, activity)
        prefix = f"/api/activities/{activity['id']}"
        history_before = self.config.history_path.read_bytes()
        preview = (await client.get(prefix + "/history-browse")).json()
        by_id = {item["occurrence_id"]: item for item in preview["records"]}
        self.assertTrue(by_id[first["id"]]["matches_current_filters"])
        self.assertFalse(by_id[second["id"]]["matches_current_filters"])
        self.assertEqual(history_before, self.config.history_path.read_bytes())
        response = await client.post(prefix + "/continue", json={"revision": activity["revision"],
            "request_id": "browse-outside-filter", "target": "test"})
        self.assertEqual("paused", response.json()["run"]["status"])
        seen = runtime.received[1]["operation_results"][0]["history_browse"]["records"]
        self.assertIn(second["id"], [item["occurrence_id"] for item in seen])
        self.assertEqual(activity["state"]["source_adopted"], response.json()["activity"]["state"]["source_adopted"])

    async def test_model_receives_actual_acquisition_results_in_same_activity(self):
        app, client, runtime, activity = await self.setup_activity([
            {"answer": "取得当前组织指定的材料", "commands": [{"type": "acquire"}]},
            {"answer": "现在可以依据返回的原文继续", "commands": []}])
        activity, first, second = await self.setup_source(app, client, activity)
        result = (await client.post(f"/api/activities/{activity['id']}/continue", json={
            "revision": activity["revision"], "request_id": "acquisition-in-activity", "target": "test"})).json()
        self.assertEqual("paused", result["run"]["status"], result)
        record = runtime.received[1]["operation_results"][0]["acquisition"]["records"][0]
        self.assertEqual(result["run"]["events"][0]["result_occurrence_id"],
                         runtime.received[1]["operation_results"][0]["result_occurrence_id"])
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

    async def test_answer_resumes_original_non_default_participant(self):
        runtime_a = ScriptedActivityRuntime([
            {"answer": "A 已收到。", "commands": []},
        ])
        runtime_b = ScriptedActivityRuntime([
            {"answer": "B 先问一个问题。", "move": {"intent": "取得一个真实条件", "execution": [
                {"type": "ask_user", "question": "请补充这个条件。"}]}},
            {"answer": "B 收到回答，继续。", "commands": []},
        ])
        participants = {
            "a": Participant("a", "默认参与者", "脚本替身 A", runtime_a),
            "b": Participant("b", "提问参与者", "脚本替身 B", runtime_b),
        }
        app = create_app(self.config, participants=participants)
        client = AsyncClient(transport=ASGITransport(app=app), base_url="http://test")
        self.addAsyncCleanup(client.aclose)
        org = (await client.post("/api/orgs", json={"name": "测试"})).json()["organization"]
        project = (await client.post(f"/api/orgs/{org['id']}/projects", json={"title": "多参与者活动"})).json()["project"]
        activity = (await client.post(f"/api/projects/{project['id']}/activities", json={})).json()["activity"]

        first = (await client.post(f"/api/activities/{activity['id']}/continue", json={
            "revision": 1, "request_id": "ask-b", "content": "开始", "target": "b"})).json()
        self.assertEqual("waiting_user", first["run"]["status"])
        move = app.state.activity_store.list_moves(activity["id"])[0]
        self.assertEqual("b", move["proposer_participant_id"])

        answered = (await client.post(f"/api/activities/{activity['id']}/moves/{move['id']}/answer", json={
            "revision": first["activity"]["revision"], "request_id": "answer-b", "answer": "这是补充条件。"})).json()
        self.assertEqual("paused", answered["run"]["status"], answered)
        self.assertEqual(2, len(runtime_b.received))
        self.assertEqual(0, len(runtime_a.received))
        self.assertIn("这是补充条件。", runtime_b.received[1]["user_message"])
        self.assertEqual("b", runtime_b.received[1]["moves"][0]["proposer_participant_id"])

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
