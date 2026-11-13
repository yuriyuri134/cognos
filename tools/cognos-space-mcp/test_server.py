"""Regression checks for the space MCP write tools: no confidence field, links to the original object kept."""
import importlib.util
import json
import os
import threading
from http.server import BaseHTTPRequestHandler, HTTPServer
from pathlib import Path
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location("cognos_space_server", Path(__file__).with_name("server.py"))
server = importlib.util.module_from_spec(spec)
spec.loader.exec_module(server)


class WriteToolTests(unittest.TestCase):
    def test_write_tools_offer_no_confidence_and_keep_links(self):
        cases = {
            "leave_trace": {"content": "attempt"},
            "add_reading": {"content": "reading"},
            "hold_premise": {"content": "premise"},
            "hit_premise": {"ref_id": 17, "content": "counterexample"},
            "answer_hit": {"hit_id": 18, "act": "还成立", "content": "evidence"},
            "hang_open": {"content": "unfinished"},
            "stop_here": {"open_id": 19, "act": "卡住", "moved": "progress"},
        }
        schemas = {tool["name"]: tool["inputSchema"] for tool in server.TOOLS}
        response = {
            "trace": {"id": 20, "kind": "attempt", "act": "卡住"},
            "reading": {"id": 21},
            "answer": {"id": 22, "act": "还成立"},
        }
        for name, fields in cases.items():
            self.assertNotIn("confidence", schemas[name]["properties"])
            with self.subTest(tool=name):
                args = {"space_id": "3", **fields}
                with patch.object(server, "ONLY", set()), patch.object(server, "call_api", return_value=response) as api:
                    result = server.run_tool(name, args)
                self.assertTrue(result)
                body = api.call_args.kwargs["body"]
                self.assertNotIn("confidence", body)
                self.assertEqual(body["subject"], server.SUBJECT)
                self.assertNotIn("space_id", body)
                self.assertIn("/creation/3/", api.call_args.args[1])
                if "ref_id" in fields:
                    self.assertEqual(body["ref_id"], fields["ref_id"])
                if "open_id" in fields:
                    self.assertEqual(body["ref_id"], fields["open_id"])
                if "hit_id" in fields:
                    self.assertIn("/hits/18/answer", api.call_args.args[1])


class _FakeOcsHandler(BaseHTTPRequestHandler):
    def do_GET(self):  # noqa: N802
        body = json.dumps({"ocs": {"data": {"spaces": []}}}).encode("utf-8")
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, fmt, *args):
        pass


class _RejectProxy(BaseHTTPRequestHandler):
    def do_GET(self):  # noqa: N802
        self.server.hits += 1
        self.send_response(502)
        self.send_header("Content-Length", "0")
        self.end_headers()

    def log_message(self, fmt, *args):
        pass


class LoopbackApiTests(unittest.TestCase):
    def test_call_api_bypasses_env_proxy_for_loopback_urls(self):
        backend = HTTPServer(("127.0.0.1", 0), _FakeOcsHandler)
        proxy = HTTPServer(("127.0.0.1", 0), _RejectProxy)
        proxy.hits = 0
        for srv in (backend, proxy):
            t = threading.Thread(target=srv.serve_forever, daemon=True)
            t.start()
            self.addCleanup(srv.server_close)
            self.addCleanup(srv.shutdown)
        proxy_url = f"http://127.0.0.1:{proxy.server_address[1]}"
        with patch.dict(os.environ, {"HTTP_PROXY": proxy_url, "HTTPS_PROXY": proxy_url}, clear=False):
            os.environ.pop("NO_PROXY", None)
            with patch.object(server, "PASSWORD", "pw"), patch.object(server, "API", f"http://127.0.0.1:{backend.server_address[1]}/ocs/v2.php/apps/cognos/api/v1.0/spaces"):
                data = server.call_api("GET", "")
        self.assertEqual({"spaces": []}, data)
        self.assertEqual(0, proxy.hits)
        self.assertFalse(server._is_loopback_url("https://example.com/ocs/v2.php"))


if __name__ == "__main__":
    unittest.main()
