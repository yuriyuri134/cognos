"""Failure and concurrency boundaries for durable MCP hand-back."""
import importlib.util
import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location("handoff_server", Path(__file__).with_name("server.py"))
server = importlib.util.module_from_spec(spec)
spec.loader.exec_module(server)


class HandoffTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.env = patch.dict(os.environ, {"COGNOS_MCP_STATE_DIR": self.tmp.name})
        self.env.start()
        self.args = {"space_id": "77", "run_id": "a" * 32, "content": "result"}
        self.empty = {"decisions_missing": [], "basis_changed": [], "drift_token": "zero"}

    def tearDown(self):
        self.env.stop()
        self.tmp.cleanup()

    def drift(self, token):
        return {"decisions_missing": [{"id": token, "content": token}],
                "basis_changed": [], "drift_token": token}

    def test_response_loss_then_process_reload_reuses_identical_result(self):
        sent = []
        def api(method, *args, **kwargs):
            if method == "GET":
                return self.empty
            sent.append(kwargs["body"].copy())
            if len(sent) == 1:
                raise OSError("response lost after server accepted")
            return {"duplicate": True}
        with patch.object(server, "call_api", side_effect=api):
            with self.assertRaises(OSError):
                server.handoff("hand_back", self.args)
        # Reimport proves pending state is on disk rather than held in globals.
        restored = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(restored)
        with patch.object(restored, "call_api", side_effect=api):
            self.assertEqual(restored.handoff("hand_back", self.args), "已交回。")
            restored.handoff("hand_back", self.args)
        self.assertEqual(len(sent), 2)
        self.assertEqual(sent[0], sent[1])
        self.assertTrue(json.loads(server._handoff_file(self.args).read_text(encoding="utf-8"))["delivered"])

    def test_uncertain_result_cannot_be_overwritten(self):
        with patch.object(server, "call_api", side_effect=[self.empty, OSError("offline")]):
            with self.assertRaises(OSError):
                server.handoff("hand_back", self.args)
        with patch.object(server, "call_api") as api:
            with self.assertRaises(RuntimeError):
                server.handoff("hand_back", {**self.args, "content": "different"})
            api.assert_not_called()

    def test_new_drift_requires_new_display_even_with_acknowledged(self):
        with patch.object(server, "call_api", return_value=self.drift("v1")) as api:
            self.assertIn("还没交", server.handoff("hand_back", {**self.args, "acknowledged": True}))
            self.assertEqual(api.call_count, 1)
        with patch.object(server, "call_api", return_value=self.drift("v2")) as api:
            self.assertIn("v2", server.handoff("hand_back", {**self.args, "acknowledged": True}))
            self.assertEqual(api.call_count, 1)
        with patch.object(server, "call_api", side_effect=[self.drift("v2"), {}]) as api:
            server.handoff("hand_back", {**self.args, "acknowledged": True})
            self.assertEqual(api.call_args.kwargs["body"]["drift_token"], "v2")
            self.assertTrue(api.call_args.kwargs["body"]["acknowledged_drift"])

    def test_check_changes_shows_all_lines_before_receipt(self):
        drift = self.drift("v3")
        drift["basis_changed"] = [{"title": "draft", "version": 1, "current": 2,
            "editor": "editor", "removed": [], "added": [str(i) for i in range(30)]}]
        with patch.object(server, "call_api", return_value=drift):
            shown = server.handoff("check_changes", self.args)
        self.assertIn("加了：29", shown)

    def test_state_is_isolated_by_subject_and_space(self):
        original = server._handoff_file(self.args)
        with patch.object(server, "SUBJECT", "another participant"):
            self.assertNotEqual(original, server._handoff_file(self.args))
        self.assertNotEqual(original, server._handoff_file({**self.args, "space_id": "78"}))

    def test_disk_failure_prevents_network_submission(self):
        with patch.object(server, "call_api", return_value=self.empty) as api, patch.object(server, "_save_handoff", side_effect=OSError("disk full")):
            with self.assertRaises(OSError):
                server.handoff("hand_back", self.args)
            self.assertEqual([c.args[0] for c in api.call_args_list], ["GET"])


if __name__ == "__main__":
    unittest.main()
