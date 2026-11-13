"""Results get back: saved before sending, resent after a failure or a bridge restart, half-done runs reported as unknown."""
import importlib.util
import io
import json
import os
import tempfile
import threading
import unittest
import urllib.error
import urllib.request
from http.server import BaseHTTPRequestHandler, HTTPServer
from pathlib import Path
from unittest.mock import patch

spec = importlib.util.spec_from_file_location("cognos_claude_bridge", Path(__file__).with_name("claude_bridge.py"))
bridge = importlib.util.module_from_spec(spec)
spec.loader.exec_module(bridge)

RUN_ID = "a" * 32


def job(**extra):
    return {
        "run_id": RUN_ID, "space_type": "creation", "space_id": "7", "subject": "Claude Code",
        "system": "入口", "prompt": "原话", "caller": "交互对象", "repo": False,
        "hit_id": None, "reply_to": 12, "open_id": None, "run_started": None, "base_branch": None, **extra,
    }


class OutboxTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        for name, value in {"OUTBOX_DIR": Path(self.tmp.name) / "outbox", "SPACES_DIR": Path(self.tmp.name) / "spaces"}.items():
            patcher = patch.object(bridge, name, value)
            patcher.start()
            self.addCleanup(patcher.stop)

    def files(self, sub):
        return sorted((bridge.OUTBOX_DIR / sub).glob("*.json"))

    def run_once(self, post):
        bridge.mark_inflight(job())
        with patch.object(bridge, "run_claude", return_value="做完了"), patch.object(bridge, "post_back", side_effect=post):
            bridge.work(job())

    def test_failed_post_keeps_the_result_and_resend_delivers_it_once(self):
        self.run_once(urllib.error.URLError("Nextcloud 停着"))
        pending = self.files("pending")
        self.assertEqual(1, len(pending))
        self.assertEqual([], self.files("inflight"))
        saved = json.loads(pending[0].read_text(encoding="utf-8"))
        self.assertEqual(RUN_ID, saved["run_id"])
        self.assertEqual("做完了", saved["content"])
        self.assertEqual(12, saved["reply_to"])
        self.assertRegex(saved["result_id"], r"^[0-9a-f]{32}$")

        # 桥重启以后（或下一轮重发）：同一个 result_id 发一次，发成了就删
        with patch.object(bridge, "post_back") as post:
            self.assertEqual(0, bridge.resend_pending())
            self.assertEqual(0, bridge.resend_pending())
        post.assert_called_once()
        self.assertEqual(saved["result_id"], post.call_args.args[0]["result_id"])
        self.assertEqual([], self.files("pending"))

    def test_delivered_result_leaves_nothing_behind(self):
        posted = []
        self.run_once(lambda result: posted.append(result))
        self.assertEqual(1, len(posted))
        self.assertEqual([], self.files("pending"))
        self.assertEqual([], self.files("inflight"))

    def test_server_error_keeps_retrying_but_rejection_is_set_aside(self):
        self.run_once(urllib.error.HTTPError("u", 503, "Service Unavailable", {}, io.BytesIO(b"")))
        self.assertEqual(1, len(self.files("pending")))
        with patch.object(bridge, "post_back", side_effect=urllib.error.HTTPError("u", 404, "Not Found", {}, io.BytesIO(b"run gone"))):
            self.assertEqual(0, bridge.resend_pending())
        self.assertEqual([], self.files("pending"))
        self.assertEqual(1, len(self.files("rejected")))

    def test_run_left_half_done_by_a_dead_bridge_is_reported_unknown(self):
        bridge.mark_inflight(job())
        record = bridge.OUTBOX_DIR / "inflight" / f"{RUN_ID}.json"
        data = json.loads(record.read_text(encoding="utf-8"))
        # 记它的是本进程：还在做，不算
        self.assertEqual(set(), bridge.stale_inflight())
        data["pid"] = 999999999
        record.write_text(json.dumps(data), encoding="utf-8")
        stale = bridge.stale_inflight()
        self.assertEqual({RUN_ID}, stale)

        with patch.object(bridge, "cognos_post", side_effect=urllib.error.URLError("还没起来")):
            bridge.report_unknown(stale)
        self.assertEqual({RUN_ID}, stale)
        self.assertTrue(record.is_file())

        with patch.object(bridge, "cognos_post") as post:
            bridge.report_unknown(stale)
        self.assertEqual(f"runs/{RUN_ID}/unknown", post.call_args.args[0])
        self.assertEqual(set(), stale)
        self.assertFalse(record.is_file())

    def test_post_back_sends_ids_and_keeps_space_in_the_url(self):
        with patch.object(bridge, "cognos_post") as post:
            bridge.post_back({"result_id": "b" * 32, "run_id": RUN_ID, "space_type": "creation", "space_id": "7",
                              "subject": "Claude Code", "content": "答"})
        path, body = post.call_args.args
        self.assertEqual("spaces/creation/7/messages", path)
        self.assertEqual({"result_id": "b" * 32, "run_id": RUN_ID, "subject": "Claude Code", "content": "答"}, body)


class _OkHandler(BaseHTTPRequestHandler):
    def do_GET(self):  # noqa: N802
        body = json.dumps({"ok": True}).encode("utf-8")
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_POST(self):  # noqa: N802
        length = int(self.headers.get("Content-Length", "0"))
        _ = self.rfile.read(length)
        self.server.received.append((self.path, self.headers.get("Authorization", "")))
        body = json.dumps({"ocs": {"data": {"ok": True}}}).encode("utf-8")
        self.send_response(201)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, fmt, *args):
        pass


class _RejectProxyHandler(BaseHTTPRequestHandler):
    def do_GET(self):  # noqa: N802
        self._reject()

    def do_POST(self):  # noqa: N802
        self._reject()

    def _reject(self):
        self.server.hits += 1
        self.send_response(502)
        self.send_header("Content-Length", "0")
        self.end_headers()

    def log_message(self, fmt, *args):
        pass


class LoopbackProxyTests(unittest.TestCase):
    def setUp(self):
        self.backend = HTTPServer(("127.0.0.1", 0), _OkHandler)
        self.backend.received = []
        self.proxy = HTTPServer(("127.0.0.1", 0), _RejectProxyHandler)
        self.proxy.hits = 0
        for srv in (self.backend, self.proxy):
            t = threading.Thread(target=srv.serve_forever, daemon=True)
            t.start()
            self.addCleanup(srv.server_close)
            self.addCleanup(srv.shutdown)
        self.backend_url = f"http://127.0.0.1:{self.backend.server_address[1]}"
        self.proxy_url = f"http://127.0.0.1:{self.proxy.server_address[1]}"

    def test_is_loopback_url_handles_ipv4_ipv6_localhost_and_remote_hosts(self):
        for url in (
            "http://127.0.0.1:8780/status.php",
            "http://127.0.2.3:8780/test",
            "http://localhost:8780",
            "http://LOCALHOST.:8780/api",
            "http://sub.localhost:8780/",
            "http://[::1]:8780/status.php",
        ):
            self.assertTrue(bridge._is_loopback_url(url), url)
        for url in (
            "https://example.com/api",
            "http://192.168.1.10:8780",
            "http://10.0.0.2:8780",
            "",
        ):
            self.assertFalse(bridge._is_loopback_url(url), url)

    def test_cognos_post_bypasses_env_proxy_for_loopback(self):
        env_patch = {"HTTP_PROXY": self.proxy_url, "HTTPS_PROXY": self.proxy_url, "ALL_PROXY": self.proxy_url}
        with patch.dict(os.environ, env_patch, clear=False):
            os.environ.pop("NO_PROXY", None)
            os.environ.pop("no_proxy", None)
            with patch.object(bridge, "COGNOS_URL", self.backend_url), patch.object(bridge, "APP_PASSWORD", "pw"):
                bridge.cognos_post("spaces/creation/1/messages", {"hello": "world"})
        self.assertEqual(0, self.proxy.hits)
        self.assertEqual(1, len(self.backend.received))
        self.assertEqual("/ocs/v2.php/apps/cognos/api/v1.0/spaces/creation/1/messages", self.backend.received[0][0])

    def test_cognos_post_bypasses_cached_winreg_proxy_after_proxy_enable_turns_off(self):
        try:
            import winreg
        except ImportError:
            self.skipTest("Windows registry test only")
        prev_opener = urllib.request._opener
        urllib.request._opener = None
        self.addCleanup(setattr, urllib.request, "_opener", prev_opener)
        state = {"enable": 1}
        real_qve = winreg.QueryValueEx

        def fake_qve(key, name):
            if name == "ProxyEnable":
                return (state["enable"], 4)
            if name == "ProxyServer":
                return (f"127.0.0.1:{self.proxy.server_address[1]}", 1)
            if name == "ProxyOverride":
                return ("localhost;127.*;<local>", 1)
            return real_qve(key, name)

        with patch.dict(os.environ, {}, clear=False):
            for k in ("HTTP_PROXY", "http_proxy", "HTTPS_PROXY", "https_proxy", "ALL_PROXY", "all_proxy"):
                os.environ.pop(k, None)
            with patch("winreg.QueryValueEx", side_effect=fake_qve), patch.object(bridge, "COGNOS_URL", self.backend_url), patch.object(bridge, "APP_PASSWORD", "pw"):
                with bridge._open_url(self.backend_url + "/status.php", timeout=3) as resp:
                    self.assertEqual(200, resp.status)
                # Flip system proxy off after global opener would otherwise be pinned
                state["enable"] = 0
                bridge.cognos_post("spaces/creation/1/messages", {"hello": "world"})
        self.assertEqual(0, self.proxy.hits)
        self.assertEqual(1, len(self.backend.received))


if __name__ == "__main__":
    unittest.main()
