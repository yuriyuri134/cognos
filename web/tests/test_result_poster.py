"""The E4 CLI authenticates with a cookie before submitting a result."""
import io
import json
import sys
import tempfile
import unittest
from contextlib import redirect_stdout
from pathlib import Path
from unittest.mock import patch

import httpx

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from handoff import result_poster


class ResultPosterTest(unittest.TestCase):
    def test_cli_login_cookie_result_logout_without_credentials_in_result(self):
        calls = []

        def handle(request):
            calls.append(request.url.path)
            body = json.loads(request.content or b"{}")
            if request.url.path == "/api/auth/login":
                self.assertEqual({"username": "tester", "password": "fake-password"}, body)
                return httpx.Response(200, json={"account": {"username": "tester"}},
                                      headers={"set-cookie": "cognos_session=fake-session; Path=/; HttpOnly; SameSite=Lax"})
            self.assertIn("cognos_session=fake-session", request.headers.get("cookie", ""))
            self.assertNotIn("fake-password", request.content.decode())
            self.assertNotIn("fake-session", request.content.decode())
            return httpx.Response(200, json={"status": "ok"})

        with tempfile.TemporaryDirectory() as temporary:
            payload = Path(temporary) / "payload.json"
            payload.write_text(json.dumps({"handoff_id": "h1", "final_assistant_text": "result"}), encoding="utf-8")
            client = httpx.Client(transport=httpx.MockTransport(handle))
            output = io.StringIO()
            with patch.object(result_poster.getpass, "getpass", return_value="fake-password"), \
                 patch.object(result_poster.httpx, "Client", return_value=client), redirect_stdout(output):
                self.assertEqual(0, result_poster.main([str(payload), "--username", "tester"]))
            self.assertEqual(["/api/auth/login", "/api/handoff/result", "/api/auth/logout"], calls)
            self.assertNotIn("fake-password", output.getvalue())
            self.assertNotIn("fake-session", output.getvalue())

    def test_bad_login_never_submits_result(self):
        calls = []

        def handle(request):
            calls.append(request.url.path)
            return httpx.Response(401, json={"detail": "用户名或密码不正确"})

        with tempfile.TemporaryDirectory() as temporary:
            payload = Path(temporary) / "payload.json"
            payload.write_text('{"handoff_id":"h1"}', encoding="utf-8")
            client = httpx.Client(transport=httpx.MockTransport(handle))
            output = io.StringIO()
            with patch.object(result_poster.getpass, "getpass", return_value="bad-password"), \
                 patch.object(result_poster.httpx, "Client", return_value=client), redirect_stdout(output):
                self.assertEqual(1, result_poster.main([str(payload), "--username", "tester"]))
            self.assertEqual(["/api/auth/login"], calls)
            self.assertNotIn("bad-password", output.getvalue())
