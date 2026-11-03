"""Results get back: saved before sending, resent after a failure or a bridge restart, half-done runs reported as unknown."""
import importlib.util
import io
import json
import tempfile
import unittest
import urllib.error
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


if __name__ == "__main__":
    unittest.main()
