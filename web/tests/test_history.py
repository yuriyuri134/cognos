"""追加历史的最小不变量。"""

from __future__ import annotations

import sys
import tempfile
import unittest
from pathlib import Path


WEB_DIR = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(WEB_DIR))

from history import AppendOnlyHistory, HistoryCorruptionError


class AppendOnlyHistoryTest(unittest.TestCase):
    def setUp(self) -> None:
        self.temporary_directory = tempfile.TemporaryDirectory()
        self.path = Path(self.temporary_directory.name) / "nested" / "history.jsonl"
        self.history = AppendOnlyHistory(self.path)

    def tearDown(self) -> None:
        self.temporary_directory.cleanup()

    def test_appends_exact_content_and_keeps_old_record(self) -> None:
        first = self.history.append_message(author="小林", content="第一句\n仍是原文")
        second = self.history.append_message(
            author="Codex",
            content="第二句",
            in_reply_to=first["id"],
            runtime_ref={"runtime": "codex", "thread_id": "t-1", "turn_id": "r-1"},
        )

        records = self.history.read_all()
        self.assertEqual(2, len(records))
        self.assertEqual("第一句\n仍是原文", records[0]["content"])
        self.assertEqual(first["id"], records[1]["in_reply_to"])
        self.assertEqual("r-1", second["runtime_ref"]["turn_id"])

    def test_preserves_unknown_future_fields_when_reading(self) -> None:
        self.path.parent.mkdir(parents=True)
        self.path.write_text('{"id":"old","future_view":{"open":true}}\n', encoding="utf-8")

        self.assertEqual({"open": True}, self.history.read_all()[0]["future_view"])

    def test_refuses_to_hide_corrupted_line(self) -> None:
        self.path.parent.mkdir(parents=True)
        self.path.write_text('{"id":"ok"}\nnot-json\n', encoding="utf-8")

        with self.assertRaisesRegex(HistoryCorruptionError, "第 2 行"):
            self.history.read_all()


if __name__ == "__main__":
    unittest.main()
