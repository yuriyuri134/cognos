import sys
import unittest
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from activity_transfer import discover_experience_candidates, prepare_transfer
from activity_program import execute_program, ProgramError


class TransferTest(unittest.TestCase):

    def test_experience_discovery_only_exposes_actually_used_documents(self):
        class Store:
            def __init__(self):
                self.activities = {}
                self.activity_runs = {}

            def list(self, project_id):
                return [{"id": item["id"], "revision": item["revision"],
                         "title": item["state"]["title"]}
                        for item in self.activities.values() if item["project_id"] == project_id]

            def get(self, ident):
                return self.activities.get(ident)

            def runs(self, ident):
                return self.activity_runs.get(ident, [])

        store = Store()
        current = {"id": "current", "project_id": "p", "revision": 1,
                   "state": {"title": "当前", "documents": {}}}
        source = {"id": "source", "project_id": "p", "revision": 3,
                  "state": {"title": "旧学习活动", "documents": {
                      "program": {"title": "二维变换构造", "format": "json", "immutable": False,
                                  "versions": [{"body": {"op": "literal", "value": 1}, "actor": "m"}]},
                      "result": {"title": "实际计算结果", "format": "json", "immutable": True,
                                 "versions": [{"body": 7, "actor": "Cognos"}]},
                      "unused": {"title": "没用过的草稿", "format": "text", "immutable": False,
                                 "versions": [{"body": "x", "actor": "m"}]},
                  }, "adopted": {"document": "program", "version": 1}, "source_adopted": None}}
        store.activities = {"current": current, "source": source}
        store.activity_runs["source"] = [{"events": [{"results": [
            {"type": "evaluate", "status": "completed", "document": "result",
             "result_occurrence_id": "occ-1"}
        ]}]}]

        found = discover_experience_candidates(store, current)
        self.assertEqual({"program", "result"}, {item["document"] for item in found})
        program = next(item for item in found if item["document"] == "program")
        self.assertEqual("construction", program["kind"])
        self.assertFalse(program["automatic_adoption"])
        self.assertEqual("unverified_candidate", program["applicability"])

    def test_data_shaped_like_code_is_not_rewritten_and_versions_are_pinned(self):
        def doc(*bodies):
            return {"title": "材料", "format": "json", "immutable": False,
                    "versions": [{"body": body, "actor": "test"} for body in bodies]}
        source = {"id": "a", "project_id": "p", "revision": 4, "state": {"documents": {
            "helper": doc({"op": "literal", "value": 3}, {"op": "literal", "value": 9}),
            "root": doc({"op": "object", "fields": {
                "value": {"op": "call", "document": "helper"},
                "data": {"op": "read", "document": "helper", "version": 1},
                "literal": {"op": "literal", "value": {"op": "read", "document": "do-not-resolve"}}}})},
            "adopted": {"document": "root", "version": 1, "program_versions": {"helper": 1}}}}
        transfer = prepare_transfer(source, "root", kind="construction")
        documents = {item["id"]: doc(item["body"]) for item in transfer["operations"]}
        result = execute_program({"documents": documents}, {"op": "call", "document": transfer["document"]})
        self.assertEqual({"value": 3, "data": {"op": "literal", "value": 3},
                          "literal": {"op": "read", "document": "do-not-resolve"}}, result.value)
        self.assertEqual(3, len(transfer["manifest"]["origins"]))

    def test_invalid_construction_is_not_silently_copied_as_executable(self):
        source = {"id": "a", "project_id": "p", "revision": 1, "state": {"documents": {
            "root": {"title": "无效构造", "format": "json", "immutable": False,
                     "versions": [{"body": {"op": "read", "document": "missing"}, "actor": "test"}]}}}}
        with self.assertRaises(ProgramError):
            prepare_transfer(source, "root", kind="construction")
        self.assertEqual(1, len(prepare_transfer(source, "root", kind="material")["manifest"]["origins"]))
