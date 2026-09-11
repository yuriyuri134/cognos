"""检查实际组织作用与恢复契约；不把预设计算当成认知涌现。"""

from __future__ import annotations

import sys
import tempfile
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from activity_program import ProgramError, compile_activity, document_version, execute_program
from activity_store import ActivityConflict, ActivityStore, material_catalog


def add(ident, body, format_name="json", *, immutable=False):
    return {"action": "add_document", "id": ident, "title": ident,
            "format": format_name, "body": body, "immutable": immutable}


def read(ident):
    return {"op": "read", "document": ident}


def select(ident, *path):
    return {"op": "select", "input": read(ident), "path": list(path)}


class ActivityCoreTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.path = Path(self.directory.name) / "activities.db"
        self.store = ActivityStore(self.path)
        self.activity = self.store.create("project", "")

    def tearDown(self):
        self.directory.cleanup()

    def change(self, *operations, allow_sources=False):
        self.activity = self.store.change(self.activity["id"], self.activity["revision"], list(operations),
                                          actor="测试参与者", reason="具体修改", allow_sources=allow_sources)
        return self.activity["state"]

    def test_learning_reorganizes_the_comparison_and_remains_reopenable(self):
        # 两种比较的数学内容由此测试预置；检验的是程序是否真的改变了比较结果。
        self.change(add("figures", [{"part": 2, "whole": 4}, {"part": 4, "whole": 8}], immutable=True),
                    allow_sources=True)
        counts = {"op": "list", "inputs": [select("figures", i, "part") for i in (0, 1)]}
        self.change(add("comparison", counts), {"action": "adopt", "document": "comparison"})
        count_revision = self.activity["revision"]
        self.assertEqual([2, 4], compile_activity(self.activity["state"]).value)
        proportions = {"op": "list", "inputs": [
            {"op": "calculate", "operator": "/", "left": select("figures", i, "part"),
             "right": select("figures", i, "whole")} for i in (0, 1)
        ]}
        self.change({"action": "revise_document", "document": "comparison", "body": proportions})
        self.assertEqual([2, 4], compile_activity(self.activity["state"]).value)
        self.change({"action": "adopt", "document": "comparison"},
                    {"action": "set_focus", "text": "相同究竟保留了什么？"})
        result = compile_activity(self.activity["state"])
        self.assertEqual([0.5, 0.5], result.value)
        self.assertIn({"document": "figures", "version": 1}, result.reads)
        restored = ActivityStore(self.path).get(self.activity["id"])
        self.assertEqual([0.5, 0.5], compile_activity(restored["state"]).value)
        previous = self.store.get(self.activity["id"], count_revision)
        self.assertEqual([2, 4], compile_activity(previous["state"]).value)

    def test_creation_does_not_require_a_question_or_turn_description_into_an_event(self):
        self.assertEqual("", self.activity["state"]["focus"])
        self.assertEqual("", compile_activity(self.activity["state"]).text)
        self.change(add("fragment", "窗还亮着。\n窗还亮着。", "text", immutable=True),
                    add("unselected", "我常被音乐触发。", "text", immutable=True), allow_sources=True)
        self.change(add("arrangement", {"op": "replace", "input": read("fragment"),
                                       "old": "窗", "new": "灯"}),
                    {"action": "adopt", "document": "arrangement"})
        self.assertEqual("灯还亮着。\n灯还亮着。", compile_activity(self.activity["state"]).text)
        self.assertIn("unselected", [item["id"] for item in material_catalog(self.activity["state"])])
        self.assertEqual("我常被音乐触发。", execute_program(self.activity["state"], read("unselected")).text)
        self.change({"action": "revise_document", "document": "arrangement",
                     "body": {"op": "slice", "input": read("fragment"), "end": 5}},
                    {"action": "adopt", "document": "arrangement"})
        self.assertEqual("窗还亮着。", compile_activity(self.activity["state"]).text)
        self.change({"action": "release"})
        self.assertIn("我常被音乐触发。", compile_activity(self.activity["state"]).text)
        self.assertEqual("窗还亮着。\n窗还亮着。", document_version(self.activity["state"], "fragment")["body"])

    def test_nested_definitions_are_pinned_while_material_changes_remain_visible(self):
        self.change(add("draft", "原句", "text"), add("inner", read("draft")),
                    add("outer", {"op": "call", "document": "inner"}),
                    {"action": "adopt", "document": "outer"})
        self.change({"action": "revise_document", "document": "inner",
                     "body": {"op": "literal", "value": "新的组织"}},
                    {"action": "revise_document", "document": "draft", "body": "修改后的原句"})
        self.assertEqual("修改后的原句", compile_activity(self.activity["state"]).text)
        self.change({"action": "adopt", "document": "outer"})
        self.assertEqual("新的组织", compile_activity(self.activity["state"]).text)

    def test_source_change_is_rejected_without_partially_applying_the_batch(self):
        self.change(add("source", "保留原话", "text", immutable=True), allow_sources=True)
        previous = self.activity
        with self.assertRaisesRegex(ValueError, "原始材料不能覆盖"):
            self.change({"action": "set_focus", "text": "不应提交"},
                        {"action": "revise_document", "document": "source", "body": "改写"})
        self.assertEqual(previous, self.store.get(previous["id"]))
        with self.assertRaisesRegex(ValueError, "实际导入"):
            self.change(add("invented_source", "模型不能冒充原始导入", "text", immutable=True))

    def test_stale_writer_cannot_overwrite_another_participants_change(self):
        other = ActivityStore(self.path)
        stale = other.get(self.activity["id"])
        self.change({"action": "set_focus", "text": "当前真实修改"})
        with self.assertRaises(ActivityConflict):
            other.change(stale["id"], stale["revision"], [{"action": "set_focus", "text": "陈旧修改"}], actor="另一参与者")
        self.assertEqual("当前真实修改", other.get(stale["id"])["state"]["focus"])

    def test_material_change_can_break_a_definition_without_erasing_failure(self):
        self.change(add("material", {"x": "可以读取"}), add("selector", select("material", "x")),
                    {"action": "adopt", "document": "selector"})
        self.change({"action": "revise_document", "document": "material", "body": {"different": 1}})
        with self.assertRaisesRegex(ProgramError, "不包含"):
            compile_activity(self.activity["state"])
        self.assertEqual("selector", self.activity["state"]["adopted"]["document"])
        # 原始状态仍可回读，失败不伪造新的空结果或自动撤销采用。
        self.assertEqual("可以读取", document_version(self.activity["state"], "material", 1)["body"]["x"])

    def test_invalid_programs_have_explicit_failures(self):
        self.change(add("a", {"op": "call", "document": "b"}), add("b", {"op": "call", "document": "a"}))
        with self.assertRaisesRegex(ProgramError, "循环"):
            self.change({"action": "adopt", "document": "a"})
        with self.assertRaisesRegex(ProgramError, "尚不支持"):
            execute_program(self.activity["state"], {"op": "eval", "value": "1+1"})
        with self.assertRaisesRegex(ProgramError, "除数为零"):
            execute_program(self.activity["state"], {"op": "calculate", "operator": "/",
                                                   "left": {"op": "literal", "value": 1},
                                                   "right": {"op": "literal", "value": 0}})
        with self.assertRaisesRegex(ProgramError, "版本不存在"):
            document_version(self.activity["state"], "a", 0)
        with self.assertRaisesRegex(ProgramError, "过长"):
            execute_program(self.activity["state"], {"op": "literal", "value": "很长"}, max_output_chars=1)

    def test_expanding_text_and_large_numbers_fail_within_program_limits(self):
        literal = lambda value: {"op": "literal", "value": value}
        with self.assertRaisesRegex(ProgramError, "过长"):
            execute_program(self.activity["state"], {"op": "replace", "input": literal("a" * 200_000),
                "old": "a", "new": "b" * 200_000})
        with self.assertRaisesRegex(ProgramError, "过长"):
            execute_program(self.activity["state"], {"op": "join", "inputs": [literal("a"), literal("b")],
                "separator": "x" * 200_000})
        with self.assertRaisesRegex(ProgramError, "数值超出"):
            execute_program(self.activity["state"], {"op": "calculate", "operator": "+",
                "left": literal(10 ** 1000), "right": literal(1)})


    def test_situation_is_versioned_text_with_validated_refs(self):
        self.change(add("note", "窗还亮着。", "text", immutable=True), allow_sources=True)
        before_situation = self.activity["revision"]
        state = self.change({"action": "set_situation", "text": "还没想清楚，先留着。", "refs": [
            {"document": "note", "excerpt": "窗还亮着。", "note": "起点的句子"}]})
        self.assertEqual("还没想清楚，先留着。", state["situation"]["text"])
        ref = state["situation"]["refs"][0]
        self.assertEqual(("note", 1), (ref["document"], ref["version"]))
        # 引用不存在的版本整批失败，不留半截状态
        with self.assertRaisesRegex(ValueError, "版本不存在"):
            self.change({"action": "set_situation", "text": "不可引用",
                         "refs": [{"document": "note", "version": 9}]})
        self.assertEqual("还没想清楚，先留着。",
                         self.store.get(self.activity["id"])["state"]["situation"]["text"])
        # 旧修订仍是旧状态；理解是版本化内容，不伪造历史理解
        self.assertNotIn("situation", self.store.get(self.activity["id"], before_situation)["state"])

    def test_move_creation_idempotent_and_conflict_checked(self):
        before = self.activity["revision"]
        move, fresh = self.store.create_move(self.activity["id"], "req-1", proposer="测试参与者",
                                             basis_revision=before, intent="试一次读取",
                                             execution=[{"type": "read", "document": "missing"}])
        self.assertTrue(fresh)
        self.assertEqual("proposed", move["state"]["status"])
        again, fresh2 = self.store.create_move(self.activity["id"], "req-1", proposer="测试参与者",
                                               basis_revision=before, intent="试一次读取",
                                               execution=[{"type": "read", "document": "missing"}])
        self.assertFalse(fresh2)
        self.assertEqual(move["id"], again["id"])
        with self.assertRaisesRegex(ActivityConflict, "不同内容"):
            self.store.create_move(self.activity["id"], "req-1", proposer="测试参与者",
                                   basis_revision=before, intent="另一个意图",
                                   execution=[{"type": "read", "document": "missing"}])
        with self.assertRaisesRegex(ValueError, "理解版本"):
            self.store.create_move(self.activity["id"], "req-2", proposer="测试参与者",
                                   basis_revision=0, intent="x", execution=[])
        move["state"] = {"status": "done", "results": [{"index": 0, "status": "ok", "kind": "read"}]}
        self.store.save_move(move)
        self.assertEqual(["done"], [item["state"]["status"] for item in self.store.list_moves(self.activity["id"])])
        self.assertEqual("done", self.store.get_move(move["id"])["state"]["status"])
        restored = ActivityStore(self.path)
        self.assertEqual(1, len(restored.list_moves(self.activity["id"])))


if __name__ == "__main__":
    unittest.main()
