"""B0-B4 Boundary 薄层测试；只使用临时目录，不访问网络。"""

from __future__ import annotations

import json
import sys
import tempfile
import unittest
from pathlib import Path

WEB_DIR = Path(__file__).resolve().parents[1]
PROJECT_DIR = WEB_DIR.parent
sys.path.insert(0, str(WEB_DIR))
sys.path.insert(0, str(PROJECT_DIR))

from boundary.registry import SourceRegistry
from boundary.sources.dsh_source import DshSourceAdapter
from boundary.sources.manual_import import import_file


def write_fake_dsh_session(dsh_home: Path, session_id: str, cwd: str) -> Path:
    log_path = (
        dsh_home
        / "sessions"
        / "--tmp-cognos--"
        / session_id
        / "session.jsonl"
    )
    log_path.parent.mkdir(parents=True)
    log_path.write_text(
        json.dumps(
            {
                "type": "session",
                "version": 0,
                "id": session_id,
                "createdAt": 1,
                "cwd": cwd,
            }
        )
        + "\n"
        + json.dumps(
            {
                "type": "request/header",
                "seq": 1,
                "data": {
                    "header": {"config": {"provider": "opencode-go", "model": "deepseek-v4-pro"}}
                },
            }
        )
        + "\n"
        + json.dumps(
            {
                "type": "assistant/message",
                "seq": 2,
                "data": {
                    "turn": 1,
                    "step": 1,
                    "message": {
                        "role": "assistant",
                        "content": [{"type": "text", "text": "hello from dsh"}],
                    },
                },
            }
        )
        + "\n",
        encoding="utf-8",
    )
    return log_path


class SourceRegistryTest(unittest.TestCase):
    def test_source_and_session_are_idempotent_and_preserve_first_seen(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            registry = SourceRegistry(Path(tmp) / "registry.json")
            first = registry.upsert_source(
                source_id="dsh", source_type="dsh-session-store", native_location="/tmp/dsh"
            )
            second = registry.upsert_source(
                source_id="dsh", source_type="dsh-session-store", native_location="/tmp/dsh"
            )
            self.assertEqual(first["first_seen_at"], second["first_seen_at"])

            session_first = registry.record_session(
                source_id="dsh",
                native_session_id="session-1",
                native_ref="/tmp/session-1/session.jsonl.zstd",
                cursor={"size_bytes": 1},
            )
            session_second = registry.record_session(
                source_id="dsh",
                native_session_id="session-1",
                native_ref="/tmp/session-1/session.jsonl.zstd",
                cursor={"size_bytes": 2},
            )
            self.assertEqual(session_first["first_seen_at"], session_second["first_seen_at"])
            self.assertEqual({"size_bytes": 2}, session_second["cursor"])
            self.assertEqual(1, len(registry.list_sessions("dsh")))


class DshSourceAdapterTest(unittest.TestCase):
    def test_scan_discovers_new_session_and_skips_unchanged_on_rerun(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            dsh_home = root / "dsh-home"
            archive_root = root / "archive"
            registry = SourceRegistry(archive_root / "registry.json")

            write_fake_dsh_session(dsh_home, "session-fake", str(root / "cwd"))

            adapter = DshSourceAdapter(
                registry=registry,
                dsh_home=dsh_home,
                archive_root=archive_root,
            )
            first = adapter.scan()
            self.assertEqual(1, len(first["discovered"]))
            projection_ref = Path(first["discovered"][0]["projection_ref"])
            self.assertTrue(projection_ref.exists())
            projection = json.loads(projection_ref.read_text(encoding="utf-8"))
            self.assertEqual("session-fake", projection["native_session_id"])
            self.assertEqual("hello from dsh", projection["text_messages"][0]["text"])
            self.assertNotIn("tool/result", json.dumps(projection)[:2000])

            second = adapter.scan()
            self.assertEqual(0, len(second["discovered"]))
            self.assertEqual(0, len(second["updated"]))
            self.assertEqual(0, second["scanned_session_count"])


class ManualImportTest(unittest.TestCase):
    def test_manual_import_is_idempotent_and_archives_raw(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            material = root / "claude.md"
            material.write_text("外部来源内容", encoding="utf-8")
            archive_root = root / "archive"
            registry = SourceRegistry(archive_root / "registry.json")

            first = import_file(
                source_id="claude",
                source_type="markdown",
                path=material,
                archive_root=archive_root,
                registry=registry,
                label="测试导入",
            )
            second = import_file(
                source_id="claude",
                source_type="markdown",
                path=material,
                archive_root=archive_root,
                registry=registry,
                label="测试导入",
            )
            self.assertTrue(first["new"])
            self.assertFalse(second["new"])
            self.assertTrue(Path(first["projection"]["archive_raw_path"]).exists())
            source = registry.get_source("claude")
            self.assertEqual("available", source["status"])
            self.assertEqual("markdown", source["source_type"])


if __name__ == "__main__":
    unittest.main()
