"""Regression checks for optional confidence across the space MCP write tools."""
import importlib.util
from pathlib import Path
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location("cognos_space_server", Path(__file__).with_name("server.py"))
server = importlib.util.module_from_spec(spec)
spec.loader.exec_module(server)


class OptionalConfidenceTests(unittest.TestCase):
    def test_write_tools_preserve_omission_and_explicit_estimates(self):
        cases = {
            "leave_trace": {"content": "attempt"},
            "add_reading": {"content": "reading"},
            "hold_premise": {"content": "premise"},
            "hit_premise": {"ref_id": 17, "content": "counterexample"},
            "answer_hit": {"hit_id": 18, "act": "站住", "content": "evidence"},
            "hang_open": {"content": "unfinished"},
            "stop_here": {"open_id": 19, "act": "卡住", "moved": "progress"},
        }
        schemas = {tool["name"]: tool["inputSchema"] for tool in server.TOOLS}
        response = {
            "trace": {"id": 20, "kind": "attempt", "act": "卡住"},
            "reading": {"id": 21},
            "answer": {"id": 22, "act": "站住"},
        }
        for name, fields in cases.items():
            self.assertNotIn("confidence", schemas[name]["required"])
            self.assertIn("confidence", schemas[name]["properties"])
            for estimate in (None, 0, 80):
                with self.subTest(tool=name, confidence=estimate):
                    args = {"space_id": "3", **fields}
                    if estimate is not None:
                        args["confidence"] = estimate
                    with patch.object(server, "ONLY", set()), patch.object(server, "call_api", return_value=response) as api:
                        result = server.run_tool(name, args)
                    self.assertTrue(result)
                    body = api.call_args.kwargs["body"]
                    self.assertEqual(body.get("confidence"), estimate)
                    self.assertEqual(body["subject"], server.SUBJECT)
                    self.assertNotIn("space_id", body)
                    self.assertIn("/creation/3/", api.call_args.args[1])
                    # Optional estimates must not change the required link to the original object.
                    if "ref_id" in fields:
                        self.assertEqual(body["ref_id"], fields["ref_id"])
                    if "open_id" in fields:
                        self.assertEqual(body["ref_id"], fields["open_id"])
                    if "hit_id" in fields:
                        self.assertIn("/hits/18/answer", api.call_args.args[1])


if __name__ == "__main__":
    unittest.main()
