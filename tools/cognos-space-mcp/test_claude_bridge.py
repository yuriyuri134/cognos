"""Results get back: saved before sending, resent after a failure or a bridge restart, half-done runs reported as unknown."""
import importlib.util
import io
import json
import tempfile
import threading
import unittest
import urllib.error
import urllib.parse
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
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


class ChangeReportTests(unittest.TestCase):
    """改了代码以后，回答末尾附一份报告（.md）：它的回答、改了哪些文件、检查、整份 diff。"""

    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        patcher = patch.object(bridge, "SPACES_DIR", Path(self.tmp.name) / "spaces")
        patcher.start()
        self.addCleanup(patcher.stop)
        self.change = {
            "branch": "cognos-self/open-93-20261104-101500", "base": "master", "commit": "abc1234",
            "stat": " lib/Service/AdoptionService.php | 12 +++++\n 1 file changed",
            "diff": "--- a/lib/Service/AdoptionService.php\n+++ b/lib/Service/AdoptionService.php\n-# 旧\n+# 新\n- 减号开头的行不能被当成列表\n",
        }

    def test_report_has_answer_files_checks_and_diff(self):
        report = bridge.change_report("Claude Code", "## 改了什么\n\n把采用写进委托。", self.change, "✓ x.php")
        self.assertIn("# Claude Code 改了什么", report)
        self.assertIn("把采用写进委托。", report)
        self.assertIn("lib/Service/AdoptionService.php", report)
        self.assertIn("✓ x.php", report)
        self.assertIn("```diff", report)
        self.assertIn("- 减号开头的行不能被当成列表", report)

    def test_local_copy_is_kept_even_when_nextcloud_is_down(self):
        with patch.object(bridge, "save_report", side_effect=urllib.error.URLError("connection refused")):
            tail = bridge.attach_change("creation", "7", "Claude Code", "答", self.change, "✓ x.php")
        self.assertIn("报告没存进 Nextcloud", tail)
        self.assertIn("```diff", tail)          # 存不进时退回把 diff 贴进对话
        kept = list((bridge.SPACES_DIR / "creation-7" / "changes").glob("*.md"))
        self.assertEqual(1, len(kept))
        self.assertIn("## 它的回答", kept[0].read_text(encoding="utf-8"))

    def test_answer_gets_a_link_not_a_pasted_diff(self):
        with patch.object(bridge, "save_report", return_value="/index.php/f/421") as save:
            tail = bridge.attach_change("creation", "7", "Claude Code", "答", self.change, "✓ x.php")
        self.assertIn("报告：[cognos-self-open-93-20261104-101500.md](/index.php/f/421)", tail)
        self.assertNotIn("```diff", tail)
        self.assertIn("Claude Code 改了什么", save.call_args.args[3])

    def test_no_change_adds_nothing(self):
        self.assertEqual("", bridge.attach_change("creation", "7", "Claude Code", "答", None))

    def test_report_goes_under_cognos_dav_folder(self):
        requests_seen = []

        class FakeDav(BaseHTTPRequestHandler):
            def log_message(self, *args):
                pass

            def do_MKCOL(self):
                requests_seen.append(("MKCOL", self.path))
                made = sum(1 for method, _ in requests_seen if method == "MKCOL")
                self.send_response(200 if made == 1 else 405)  # 第二次起：已经有了
                self.end_headers()

            def do_PUT(self):
                requests_seen.append(("PUT", self.path))
                self.rfile.read(int(self.headers.get("Content-Length") or 0))
                self.send_response(201)
                self.send_header("OC-FileId", "00000421oc1xyz")
                self.end_headers()

        server = ThreadingHTTPServer(("127.0.0.1", 0), FakeDav)
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        self.addCleanup(server.server_close)
        self.addCleanup(server.shutdown)
        url = f"http://127.0.0.1:{server.server_address[1]}"
        with patch.object(bridge, "COGNOS_URL", url), patch.object(bridge, "APP_PASSWORD", "pw"):
            link = bridge.save_report("creation", "7", "x.md", "# 报告")
        self.assertEqual("/index.php/f/421", link)
        self.assertEqual("MKCOL", requests_seen[0][0])
        puts = [path for method, path in requests_seen if method == "PUT"]
        self.assertEqual(1, len(puts))
        self.assertIn("/remote.php/dav/files/", puts[0])
        self.assertIn("Cognos/", urllib.parse.unquote(puts[0]))
        self.assertIn("改动报告", urllib.parse.unquote(puts[0]))


if __name__ == "__main__":
    unittest.main()
