"""只测试来源切片与追加实录，不调用 Codex 网络运行时。"""

from __future__ import annotations

import hashlib
import json
import sys
import tempfile
import unittest
from pathlib import Path


PHASE1_DIR = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(PHASE1_DIR))

from replay_probe import append_jsonl, read_exact_lines


class ReplayProbeTest(unittest.TestCase):
    def setUp(self) -> None:
        self.temporary_directory = tempfile.TemporaryDirectory()
        self.root = Path(self.temporary_directory.name)

    def tearDown(self) -> None:
        self.temporary_directory.cleanup()

    def test_reads_exact_source_lines_and_hashes_whole_file(self) -> None:
        source = self.root / "raw.md"
        raw = "第一行\n第二行\n第三行\n".encode("utf-8")
        source.write_bytes(raw)

        excerpt, digest = read_exact_lines(source, 2, 3)

        self.assertEqual("第二行\n第三行\n", excerpt)
        self.assertEqual(hashlib.sha256(raw).hexdigest(), digest)

    def test_appends_without_replacing_earlier_record(self) -> None:
        ledger = self.root / "runs.jsonl"

        append_jsonl(ledger, {"turn_id": "turn-1", "output": "旧认识"})
        append_jsonl(ledger, {"turn_id": "turn-2", "output": "重新认识"})

        records = [json.loads(line) for line in ledger.read_text(encoding="utf-8").splitlines()]
        self.assertEqual(["turn-1", "turn-2"], [item["turn_id"] for item in records])
        self.assertEqual("旧认识", records[0]["output"])

    def test_rejects_invalid_line_range(self) -> None:
        source = self.root / "raw.md"
        source.write_text("只有一行\n", encoding="utf-8")

        with self.assertRaises(ValueError):
            read_exact_lines(source, 1, 2)


if __name__ == "__main__":
    unittest.main()
