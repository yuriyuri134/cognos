"""Packet A: context assembly is explicit, serialisable, and replayable."""

from __future__ import annotations

import hashlib
import json
import sys
import tempfile
import unittest
from pathlib import Path

WEB_DIR = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(WEB_DIR))

from activity_store import ActivityConflict, ActivityStore
from context_assembly import assemble_activity_context, project_model_context, render_activity_prompt


class ContextAssemblyTest(unittest.TestCase):
    def setUp(self):
        self.activity = {
            "id": "activity-1",
            "revision": 2,
            "project_id": "project-1",
            "state": {"title": "试探", "focus": "", "documents": {}, "adopted": None},
        }

    def test_empty_formation_is_allowed_and_context_buckets_are_named(self):
        context = assemble_activity_context(
            self.activity,
            "还没有明确问题",
            [],
            participant={"participant_id": "p1"},
            runtime_meta={"requested_model": "test"},
        )
        self.assertEqual([], context["formations"])
        self.assertEqual([], context["recent_encounters"])
        self.assertEqual("还没有明确问题", context["user_input"]["content"])
        self.assertEqual("explicit_retrieval_only", context["excluded_history"]["policy"])
        prompt = render_activity_prompt("protocol", context)
        self.assertIn("本次处境（内容材料）：", prompt)

    def test_snapshot_is_idempotent_and_hash_is_derived(self):
        with tempfile.TemporaryDirectory() as directory:
            store = ActivityStore(Path(directory) / "activity.sqlite3")
            context = {"schema_version": 1, "formations": [], "user_input": {"content": "x"}}
            prompt = "protocol\n\n本次处境（内容材料）：\n{}"
            first = store.save_context_snapshot(
                activity_id="activity-1", run_id="run-1", call_index=0,
                activity_revision=2, context=context, rendered_prompt=prompt,
            )
            second = store.save_context_snapshot(
                activity_id="activity-1", run_id="run-1", call_index=0,
                activity_revision=2, context=context,
                rendered_prompt=prompt,
                rendered_prompt_hash=first["rendered_prompt_hash"],
            )
            self.assertEqual(first["id"], second["id"])
            self.assertEqual(hashlib.sha256(prompt.encode()).hexdigest(), first["rendered_prompt_hash"])
            self.assertEqual(first, store.get_context_snapshot(first["id"]))
            self.assertEqual([first["id"]], [item["id"] for item in store.list_context_snapshots("activity-1")])
            with self.assertRaises(ActivityConflict):
                store.save_context_snapshot(
                    activity_id="activity-1", run_id="run-1", call_index=0,
                    activity_revision=2, context={"different": True}, rendered_prompt=prompt,
                )

    def test_active_materials_include_the_versioned_bodies_actually_read(self):
        self.activity["state"]["documents"] = {
            "note": {"title": "学习材料", "format": "text", "immutable": True,
                     "versions": [{"body": "特征向量的原文", "actor": "用户", "occurrence_id": "occ-1"}]}
        }
        self.activity["state"]["adopted"] = None
        context = assemble_activity_context(self.activity, "继续", [])
        entries = context["active_materials"]["entries"]
        self.assertEqual(1, len(entries))
        self.assertEqual("note", entries[0]["id"])
        self.assertEqual(1, entries[0]["version"])
        self.assertEqual("特征向量的原文", entries[0]["body"])
        self.assertEqual("compiled_activity", entries[0]["included_by"])

    def test_adopted_slice_changes_actual_input_without_erasing_sources(self):
        source = "选中段落。未选的原文仍然保留。"
        def doc(body, format_name="text"):
            return {"title": "材料", "format": format_name, "immutable": True,
                    "versions": [{"body": body, "actor": "用户"}]}
        self.activity["state"]["documents"] = {
            "source": doc(source),
            "view": doc({"op": "slice", "input": {"op": "read", "document": "source"},
                         "start": 0, "end": 5}, "json"),
        }
        self.activity["state"]["adopted"] = {"document": "view", "version": 1}
        context = assemble_activity_context(self.activity, "继续", [])
        self.assertEqual(source[:5], context["compiled"]["text"])
        self.assertNotIn(source[5:], render_activity_prompt("protocol", context))
        self.assertEqual({"source", "view"}, {item["id"] for item in context["material_catalog"]})
        entry = next(item for item in context["active_materials"]["entries"] if item["id"] == "source")
        self.assertNotIn("body", entry)
        self.assertEqual({"type": "read", "document": "source", "version": 1}, entry["read_operation"])
        # A new encounter bypasses the adopted view; selection is not censorship.
        context = assemble_activity_context(self.activity, "继续", [{"document": {"body": source}}])
        self.assertIn(source, render_activity_prompt("protocol", context))
        self.activity["state"]["adopted"] = None
        restored = assemble_activity_context(self.activity, "继续", [])
        self.assertIn(source, restored["compiled"]["text"])
        self.assertEqual(source, self.activity["state"]["documents"]["source"]["versions"][0]["body"])

    def test_cognitive_conditions_keep_tension_and_reopening_channels(self):
        context = assemble_activity_context(
            self.activity, "继续", [{"type": "constructed", "status": "ok", "body": "新例子"}],
            formation_history=[{"id": "f1", "text": "暂定读法"}],
            tension_history=[{"id": "t1", "text": "这里仍未解释", "status": "open"}],
        )
        conditions = context["cognitive_conditions"]
        self.assertEqual(1, len(conditions["tensions"]))
        self.assertEqual("这里仍未解释", conditions["tensions"][0]["text"])
        self.assertEqual(1, len(conditions["unassimilated_candidates"]))
        self.assertTrue(any(item["type"] == "external_world" for item in conditions["reopening_channels"]))
        self.assertEqual(64, len(conditions["condition_fingerprint"]))
        changed = assemble_activity_context(
            self.activity, "继续", [{"type": "constructed", "status": "ok", "body": "新例子"}],
            formation_history=[{"id": "f1", "text": "另一种读法"}],
            tension_history=[{"id": "t1", "text": "这里仍未解释", "status": "open"}],
        )
        self.assertNotEqual(conditions["condition_fingerprint"],
                            changed["cognitive_conditions"]["condition_fingerprint"])

        rendered = render_activity_prompt("protocol", context)
        model_context = json.loads(rendered.split("本次处境（内容材料）：\n", 1)[1])
        self.assertNotIn("activity_view", model_context)
        self.assertEqual(["f1"], [item["id"] for item in model_context["formations"]])
        self.assertEqual(["t1"], [item["id"] for item in model_context["tensions"]])
        self.assertNotIn("formations", model_context["cognitive_conditions"])
        self.assertNotIn("tensions", model_context["cognitive_conditions"])
        self.assertEqual(1, len(model_context["cognitive_conditions"]["unassimilated_candidates"]))

    def test_prompt_profiles_preserve_reachability_while_ablating_structure(self):
        self.activity["state"]["documents"] = {
            "source": {"title": "原始材料", "format": "text", "immutable": True,
                       "versions": [{"body": "真正需要面对的正文", "actor": "用户"}]},
        }
        context = assemble_activity_context(
            self.activity, "继续", [{"type": "constructed", "status": "ok", "body": "实际结果"}],
            formation_history=[{"id": "f1", "text": "暂定读法"}],
            tension_history=[{"id": "t1", "text": "尚未解释", "status": "open"}],
        )
        full = project_model_context(context, "full")
        minimal = project_model_context(context, "minimal")
        baseline = project_model_context(context, "baseline")

        self.assertIn("formations", full)
        self.assertIn("tensions", full)
        self.assertNotIn("activity_view", full)
        self.assertNotIn("formations", full["cognitive_conditions"])

        for key in ("formations", "dormant_formations", "formation_projection",
                    "tensions", "resolved_tensions", "cognitive_conditions"):
            self.assertNotIn(key, minimal)
            self.assertNotIn(key, baseline)

        # Minimal still receives Cognos' current material organization.
        self.assertIn("active_materials", minimal)
        self.assertIn("compiled", minimal)
        # Baseline instead gets a neutral current view plus the same catalog and
        # the same tool affordances, so underlying material remains reachable.
        self.assertNotIn("active_materials", baseline)
        self.assertEqual(context["material_catalog"], baseline["materials"]["catalog"])
        self.assertEqual(context["compiled"], baseline["materials"]["current_material_view"])
        self.assertEqual(context["affordances"], baseline["affordances"])
        self.assertEqual(context["operation_results"], baseline["operation_results"])

        with self.assertRaises(ValueError):
            project_model_context(context, "unknown")

    def test_distinct_readings_stay_distinct_and_user_edit_is_not_suppressed(self):
        self.activity["state"]["situation"] = {"text": "用户明确写下的另一理解", "refs": []}
        context = assemble_activity_context(self.activity, "继续", [], formation_history=[
            {"id": "a", "text": "同一措辞", "event_id": "1"},
            {"id": "b", "text": "同一措辞", "event_id": "2"},
            {"id": "a", "status": "dormant", "event_id": "3"},
        ])
        self.assertEqual(["b", "legacy-situation"], [x["id"] for x in context["formations"]])
        self.assertEqual("用户明确写下的另一理解", context["situation"]["text"])
        self.assertEqual(["1", "3"], context["dormant_formations"][0]["revision_event_ids"])
        self.assertEqual(["2"], context["cognitive_conditions"]["formations"][0]["revision_event_ids"])
        self.assertEqual(["b"], [x["id"] for x in context["cognitive_conditions"]["formations"]])
        self.assertEqual(["1", "3"], context["activity_view"]["formations"]["dormant"][0]["revision_event_ids"])

    def test_tension_status_and_text_revisions_preserve_omitted_fields(self):
        from cognitive_runtime import activity_view_from_runs
        runs = [{"id": "run-1", "events": [
            {"tension_updates": [{"id": "t1", "text": "这里还没解释", "refs": [{"x": 1}]}]},
            {"tension_updates": [{"id": "t1", "status": "resolved"}]},
            {"tension_updates": [{"id": "t1", "text": "文字后来被修订"}]},
        ]}]
        view = activity_view_from_runs(runs, {})
        self.assertEqual([], view["tensions"]["open"])
        resolved = view["tensions"]["resolved"][0]
        self.assertEqual("resolved", resolved["status"])
        self.assertEqual("文字后来被修订", resolved["text"])
        self.assertEqual([{"x": 1}], resolved["refs"])
        self.assertEqual([
            "run-1:0:tension:0", "run-1:1:tension:0", "run-1:2:tension:0"
        ], resolved["revision_event_ids"])

    def test_resolved_tension_and_dormant_formation_do_not_resurface_after_bounds(self):
        from cognitive_runtime import build_cognitive_conditions
        formations = [{"id": "old", "text": "较早的读法"}]
        formations += [{"id": f"f{i}", "text": "另一个读法"} for i in range(45)]
        formations += [{"id": "old", "status": "dormant"}]
        result = build_cognitive_conditions(formations=formations,
            tensions=[{"id": "t", "status": "open"}, {"id": "t", "status": "resolved"}],
            encounters=[{"status": "failed", "error": "读取失败也是真实遭遇"}])
        self.assertNotIn("old", [item["id"] for item in result["formations"]])
        self.assertEqual([], result["tensions"])
        self.assertEqual("failed", result["unassimilated_candidates"][0]["encounter"]["status"])
