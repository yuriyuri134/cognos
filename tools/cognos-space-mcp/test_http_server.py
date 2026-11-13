"""The ChatGPT HTTP entry: wrong token is 404, tools/list only shows the general space tools, calls reach server.run_tool."""
import importlib.util
import json
import os
import threading
import unittest
import urllib.error
import urllib.request
from http.server import ThreadingHTTPServer
from pathlib import Path
from unittest.mock import patch

spec = importlib.util.spec_from_file_location("cognos_http_server", Path(__file__).with_name("http_server.py"))
http_server = importlib.util.module_from_spec(spec)
spec.loader.exec_module(http_server)


class HttpEntryTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        with patch.dict(os.environ, {"COGNOS_APP_PASSWORD": "x", "COGNOS_SUBJECT": "", "COGNOS_TOOLS": ""}):
            os.environ.pop("COGNOS_SUBJECT"); os.environ.pop("COGNOS_TOOLS")
            cls.server = http_server.load_server()
        cls.httpd = ThreadingHTTPServer(("127.0.0.1", 0), http_server.make_handler(cls.server, "tok"))
        threading.Thread(target=cls.httpd.serve_forever, daemon=True).start()
        cls.base = f"http://127.0.0.1:{cls.httpd.server_address[1]}"

    @classmethod
    def tearDownClass(cls):
        cls.httpd.shutdown()

    def post(self, path, body):
        req = urllib.request.Request(self.base + path, data=json.dumps(body).encode(), method="POST",
                                     headers={"Content-Type": "application/json"})
        with urllib.request.urlopen(req, timeout=5) as resp:
            return resp.status, (json.loads(resp.read() or b"null"))

    def test_wrong_token_is_404(self):
        with self.assertRaises(urllib.error.HTTPError) as caught:
            self.post("/mcp/wrong", {"jsonrpc": "2.0", "id": 1, "method": "ping"})
        self.assertEqual(caught.exception.code, 404)

    def test_lists_only_general_tools_under_chatgpt(self):
        self.assertEqual(self.server.SUBJECT, "ChatGPT")
        status, answer = self.post("/mcp/tok", {"jsonrpc": "2.0", "id": 1, "method": "tools/list"})
        names = {t["name"] for t in answer["result"]["tools"]}
        self.assertEqual(status, 200)
        self.assertEqual(names, set(http_server.DEFAULT_TOOLS))

    def test_notification_is_202_and_call_reaches_tool(self):
        status, _ = self.post("/mcp/tok", {"jsonrpc": "2.0", "method": "notifications/initialized"})
        self.assertEqual(status, 202)
        with patch.object(self.server, "call_api", return_value=[]) as api:
            status, answer = self.post("/mcp/tok/", {"jsonrpc": "2.0", "id": 2, "method": "tools/call",
                                                      "params": {"name": "list_spaces", "arguments": {}}})
        self.assertEqual(status, 200)
        self.assertTrue(api.called)
        self.assertIn("content", answer["result"])

    def test_delegation_tool_refused(self):
        _, answer = self.post("/mcp/tok", {"jsonrpc": "2.0", "id": 3, "method": "tools/call",
                                           "params": {"name": "hand_back", "arguments": {}}})
        self.assertTrue(answer["result"]["isError"])


if __name__ == "__main__":
    unittest.main()
