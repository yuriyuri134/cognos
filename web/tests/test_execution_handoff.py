"""Execution Handoff E1-E4 的薄层测试；全部使用临时目录，不访问网络。"""

from __future__ import annotations

import json
import os
import sys
import tempfile
import unittest
from pathlib import Path

from httpx import ASGITransport, AsyncClient

WEB_DIR = Path(__file__).resolve().parents[1]
PROJECT_DIR = WEB_DIR.parent
PHASE0_DIR = PROJECT_DIR / "phase0"
sys.path.insert(0, str(WEB_DIR))
sys.path.insert(0, str(PHASE0_DIR))

from codex_runtime import RuntimeReply
from handoff.dsh_runner import DshRunnerError, encode_workspace_dir, run_envelope
from handoff.envelope import build_envelope, build_dsh_prompt, load_envelope, write_envelope
from handoff.exporter import (
    build_vault_result,
    collect_test_provenance,
    export_session,
    redact_secrets,
)
from server import AppConfig, create_app


class FakeRuntime:
    async def send(self, message: str) -> RuntimeReply:
        return RuntimeReply(
            thread_id="thread-handoff-test",
            turn_id=f"turn-{len(message)}",
            text=f"handoff-test 回复：{message}",
        )


def iso_now() -> str:
    from datetime import datetime, timezone

    return datetime.now(timezone.utc).isoformat()


class EnvelopeTest(unittest.TestCase):
    def test_envelope_round_trip_and_projection_marker(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            envelope = build_envelope(
                handoff_id="h1",
                created_at=iso_now(),
                working_directory=str(Path(tmp).resolve()),
                instruction="只创建一个探针文件",
                occurrence_refs=["occ-1"],
                source_refs=["vault/来源.md"],
                occurrence_records=[
                    {"id": "occ-1", "author": "Gemini", "content": "原始发生", "occurred_at": iso_now()}
                ],
                envelope_path=str(Path(tmp) / "h1.json"),
            )
            path = write_envelope(envelope, Path(tmp) / "h1.json")
            loaded = load_envelope(path)
            self.assertEqual("dsh-headless", loaded["executor"]["hint"])

            prompt = build_dsh_prompt(path)
            self.assertIn("projection/cache", prompt)
            self.assertIn("不是真相源", prompt)
            self.assertIn("occ-1", prompt)
            self.assertIn("只创建一个探针文件", prompt)

    def test_load_envelope_rejects_non_headless_executor(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "bad.json"
            path.write_text(
                json.dumps(
                    {
                        "handoff_id": "h1",
                        "created_at": iso_now(),
                        "working_directory": tmp,
                        "instruction": "x",
                        "occurrence_refs": [],
                        "source_refs": [],
                        "executor": {"hint": "web"},
                    }
                ),
                encoding="utf-8",
            )
            from handoff.envelope import HandoffEnvelopeError

            with self.assertRaises(HandoffEnvelopeError):
                load_envelope(path)


class DshRunnerTest(unittest.TestCase):
    def test_workspace_encoding_matches_current_dsh_convention(self) -> None:
        self.assertEqual(
            "--mnt-c-Users-lnp-Documents-Playground-cognos--",
            encode_workspace_dir("/mnt/c/Users/lnp/Documents/Playground/cognos"),
        )

    def _write_fake_dsh(self, root: Path, cwd: Path, *, write_turn_end: bool, exit_code: int) -> Path:
        slug = encode_workspace_dir(cwd)
        if os.name == "nt":
            # Windows：CreateProcess 可直接执行 .cmd；模拟 dsh 写 session log 并退出
            fake_dsh = root / "fake-dsh.cmd"
            log_dir = f"%DSH_HOME%\\sessions\\{slug}\\session-fake-s1"
            log_path = f"%DSH_HOME%\\sessions\\{slug}\\session-fake-s1\\session.jsonl"
            cwd_esc = str(cwd).replace("\\", "\\\\").replace("%", "%%")
            lines = ["@echo off"]
            lines.append(f'mkdir "{log_dir}" 2>nul')
            lines.append(
                f'echo {{"type":"session","id":"session-fake-s1","cwd":"{cwd_esc}"}} > "{log_path}"'
            )
            if write_turn_end:
                lines.append(
                    'echo {"type":"turn/end","data":{"turn":1,"reason":{"kind":"completed"}}} >> '
                    + f'"{log_path}"'
                )
            lines.append(f"exit /b {exit_code}")
            fake_dsh.write_text("\r\n".join(lines) + "\r\n", encoding="utf-8")
            return fake_dsh

        # POSIX（WSL / Linux / macOS）：保留原 shell 脚本
        fake_dsh = root / "fake-dsh"
        log_path = "$DSH_HOME/sessions/" + slug + "/session-fake-s1/session.jsonl"
        script = "#!/bin/sh\n" + 'mkdir -p "$DSH_HOME/sessions/' + slug + '/session-fake-s1"\n'
        script += (
            "printf '%s\\n' "
            + "'{\"type\":\"session\",\"id\":\"session-fake-s1\",\"cwd\":\""
            + str(cwd)
            + "\"}' > "
            + log_path
            + "\n"
        )
        if write_turn_end:
            script += (
                "printf '%s\\n' "
                + "'{\"type\":\"turn/end\",\"data\":{\"turn\":1,\"reason\":{\"kind\":\"completed\"}}}' >> "
                + log_path
                + "\n"
            )
        script += f"exit {exit_code}\n"
        fake_dsh.write_text(script, encoding="utf-8")
        fake_dsh.chmod(0o755)
        return fake_dsh

    def _run_with_fake_dsh(self, root: Path, cwd: Path, *, write_turn_end: bool, exit_code: int):
        dsh_home = root / "dsh-home"
        sessions_dir = dsh_home / "sessions" / encode_workspace_dir(cwd)
        sessions_dir.mkdir(parents=True)
        fake_dsh = self._write_fake_dsh(root, cwd, write_turn_end=write_turn_end, exit_code=exit_code)

        envelope_path = root / "h.json"
        write_envelope(
            build_envelope(
                handoff_id="h-runner",
                created_at=iso_now(),
                working_directory=str(cwd),
                instruction="探针",
                occurrence_refs=[],
                source_refs=[],
                occurrence_records=[],
            ),
            envelope_path,
        )
        return run_envelope(
            envelope_path,
            dsh_home=dsh_home,
            dsh_bin=str(fake_dsh),
        )

    def test_run_envelope_with_fake_dsh_finds_new_session(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            cwd = root / "workspace"
            cwd.mkdir()
            result = self._run_with_fake_dsh(root, cwd, write_turn_end=True, exit_code=0)
            self.assertEqual("session-fake-s1", result.session_id)
            self.assertTrue(result.session_log_path.endswith("session.jsonl"))

    def test_run_envelope_raises_on_nonzero_exit(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            cwd = root / "workspace"
            cwd.mkdir()
            with self.assertRaises(DshRunnerError) as ctx:
                self._run_with_fake_dsh(root, cwd, write_turn_end=True, exit_code=1)
            self.assertIn("非零退出码", str(ctx.exception))
            self.assertIn("exit_code=1", str(ctx.exception))

    def test_run_envelope_raises_when_turn_end_missing(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            cwd = root / "workspace"
            cwd.mkdir()
            with self.assertRaises(DshRunnerError) as ctx:
                self._run_with_fake_dsh(root, cwd, write_turn_end=False, exit_code=0)
            self.assertIn("turn/end", str(ctx.exception))


def sample_events() -> list[dict]:
    return [
        {
            "type": "session",
            "version": 0,
            "id": "session-sample",
            "createdAt": 1,
            "cwd": "/tmp/cognos",
        },
        {
            "type": "request/header",
            "seq": 1,
            "data": {"header": {"config": {"provider": "opencode-go", "model": "deepseek-v4-pro"}}},
        },
        {
            "type": "tool/call",
            "seq": 2,
            "data": {
                "turn": 1,
                "step": 1,
                "callId": "call-1",
                "name": "bash",
                "arguments": '{"command":"python -m pytest -q"}',
            },
        },
        {
            "type": "tool/result",
            "seq": 3,
            "data": {
                "turn": 1,
                "step": 1,
                "message": {
                    "source": {"kind": "tool", "callId": "call-1"},
                    "content": [
                        {
                            "type": "tool-result",
                            "toolCallId": "call-1",
                            "isError": False,
                            "content": [{"type": "text", "text": "2 passed in 0.10s"}],
                        }
                    ],
                },
            },
        },
        {
            "type": "assistant/message",
            "seq": 4,
            "data": {
                "turn": 1,
                "step": 2,
                "message": {
                    "role": "assistant",
                    "content": [
                        {"type": "text", "text": "测试运行结束。"},
                    ],
                },
            },
        },
        {
            "type": "turn/end",
            "seq": 5,
            "data": {"turn": 1, "reason": {"kind": "completed"}},
        },
    ]


class ExporterTest(unittest.TestCase):
    def test_export_session_reads_multiframe_zstd(self) -> None:
        try:
            import zstandard
        except ImportError:
            self.skipTest("zstandard not installed")

        with tempfile.TemporaryDirectory() as tmp:
            log_path = Path(tmp) / "session.jsonl.zstd"
            compressor = zstandard.ZstdCompressor()
            frames = [
                compressor.compress(
                    (json.dumps(item, ensure_ascii=False) + "\n").encode("utf-8")
                )
                for item in sample_events()
            ]
            log_path.write_bytes(b"".join(frames))

            export = export_session(log_path, working_directory=tmp)
            self.assertEqual("session-sample", export["session_ref"]["session_id"])
            self.assertEqual(log_path.resolve(), Path(export["session_ref"]["native_log_path"]))
            self.assertEqual("dsh", export["executor"])
            self.assertEqual("opencode-go", export["provider"])
            self.assertEqual("deepseek-v4-pro", export["model"])
            self.assertIn("python -m pytest -q", export["commands_run"])
            self.assertEqual([], export["tool_errors"])
            self.assertEqual(1, len(export["tests"]))
            self.assertIsNone(export["tests"][0]["exit_code"])
            self.assertEqual(1, len(export["tool/call"]))
            self.assertEqual(1, len(export["tool/result"]))

    def test_export_session_is_mechanical(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            log_path = Path(tmp) / "session.jsonl"
            log_path.write_text(
                "\n".join(json.dumps(item, ensure_ascii=False) for item in sample_events()) + "\n",
                encoding="utf-8",
            )
            export = export_session(log_path, working_directory=tmp)
            self.assertEqual("session-sample", export["session_ref"]["session_id"])
            self.assertEqual(log_path.resolve(), Path(export["session_ref"]["native_log_path"]))
            self.assertEqual("dsh", export["executor"])
            self.assertEqual("opencode-go", export["provider"])
            self.assertEqual("deepseek-v4-pro", export["model"])
            self.assertIn("python -m pytest -q", export["commands_run"])
            self.assertEqual([], export["tool_errors"])
            self.assertEqual(1, len(export["tests"]))
            self.assertIsNone(export["tests"][0]["exit_code"])
            self.assertEqual("/tmp/cognos", export["cwd"])
            self.assertEqual(
                {"provider": "opencode-go", "model": "deepseek-v4-pro"},
                export["provider_model"],
            )
            self.assertEqual("测试运行结束。", export["final_assistant_text"])
            self.assertEqual("bash", export["tool/call"][0]["name"])
            self.assertEqual(False, export["tool/result"][0]["is_error"])
            self.assertEqual("2 passed in 0.10s", export["tool/result"][0]["content"])
            self.assertEqual({"kind": "completed"}, export["turn/end.reason"][0]["reason"])
            self.assertEqual("tests_passed", export["test_provenance"]["status"])

            vault_payload = build_vault_result(export, handoff_id="h-x")
            self.assertIn("session_ref", vault_payload)
            self.assertLessEqual(
                len(vault_payload["tool_result_refs"][0]["content_excerpt"]),
                4000,
            )

    def test_test_provenance_distinguishes_claim_without_run(self) -> None:
        calls = [
            {
                "seq": 1,
                "turn": 1,
                "step": 1,
                "call_id": "call-nontest",
                "name": "bash",
                "arguments": {"command": "echo done"},
            }
        ]
        proven = collect_test_provenance(
            calls,
            [],
            claimed_text="所有测试都通过了。",
            session_log_path="session.jsonl",
        )
        self.assertEqual("claimed_by_dsh_without_observed_test_result", proven["status"])
        self.assertTrue(proven["claimed_by_assistant"])

        no_claim = collect_test_provenance(
            calls,
            [],
            claimed_text="任务完成。",
            session_log_path="session.jsonl",
        )
        self.assertEqual("no_tests_run", no_claim["status"])


class RedactionTest(unittest.TestCase):
    def test_secret_redaction(self) -> None:
        self.assertEqual(
            "GEMINI_API_KEY=<redacted>",
            redact_secrets("GEMINI_API_KEY=AIzaSyA123456789012345678901234567890"),
        )
        self.assertEqual(
            "<redacted>",
            redact_secrets("Authorization: Bearer abcdefghijklmnopqrstuvwxyz123456"),
        )


class HandoffEndpointTest(unittest.IsolatedAsyncioTestCase):
    def setUp(self) -> None:
        self.temporary_directory = tempfile.TemporaryDirectory()
        root = Path(self.temporary_directory.name)
        instructions = root / "participant.md"
        instructions.write_text("测试说明", encoding="utf-8")
        self.config = AppConfig(
            history_path=root / "vault" / "first-field.jsonl",
            thread_state_dir=root / "state",
            codex_cwd=root,
            participant_instructions_path=instructions,
        )

    def tearDown(self) -> None:
        self.temporary_directory.cleanup()

    async def test_handoff_create_envelope_and_result_provenance_round_trip(self) -> None:
        app = create_app(self.config, runtime=FakeRuntime())
        async with AsyncClient(
            transport=ASGITransport(app=app), base_url="http://test"
        ) as client:
            source = await client.post("/api/messages", json={"content": "这里是 A 的原始判断"})
            source_id = source.json()["reply"]["id"]

            created = await client.post(
                "/api/handoff/create",
                json={
                    "instruction": "修改探针文件并运行测试",
                    "target_agent": "DSH",
                    "working_directory": str(self.config.codex_cwd),
                    "occurrence_refs": [source_id],
                    "source_refs": ["vault/来源.md"],
                },
            )
            self.assertEqual(200, created.status_code)
            handoff_record = created.json()["record"]
            envelope_path = Path(created.json()["envelope_path"])
            self.assertTrue(envelope_path.exists())

            envelope = json.loads(envelope_path.read_text(encoding="utf-8"))
            self.assertEqual(handoff_record["id"], envelope["handoff_id"])
            self.assertEqual("dsh-headless", envelope["executor"]["hint"])
            self.assertEqual(source_id, envelope["occurrence_projection"]["occurrences"][0]["occurrence"]["id"])

            received = await client.post(
                "/api/handoff/result",
                json={
                    "handoff_id": handoff_record["id"],
                    "agent_name": "DSH",
                    "agent_response": "[Cognos Handoff Result R] 机械结果",
                    "changed_files": ["web/handoff/envelope.py"],
                    "commands_run": ["python -m pytest -q"],
                    "errors": [],
                    "session_ref": {
                        "session_id": "session-fake",
                        "session_log_path": "/tmp/session-fake/session.jsonl.zstd",
                    },
                    "related_occurrence_ids": [source_id],
                    "git_provenance": {"head": "abc123", "available": True},
                    "test_provenance": {"status": "tests_passed"},
                },
            )
            self.assertEqual(200, received.status_code)

            boot = (await client.get("/api/bootstrap")).json()["messages"]
            self.assertEqual(4, len(boot))
            result_record = boot[-1]
            self.assertEqual("DSH", result_record["author"])
            self.assertEqual(handoff_record["id"], result_record["in_reply_to"])
            self.assertEqual(
                "session-fake",
                result_record["handoff_result"]["session_ref"]["session_id"],
            )
            self.assertEqual(
                "tests_passed",
                result_record["handoff_result"]["test_provenance"]["status"],
            )

    async def test_result_rejects_unknown_handoff_id(self) -> None:
        app = create_app(self.config, runtime=FakeRuntime())
        async with AsyncClient(
            transport=ASGITransport(app=app), base_url="http://test"
        ) as client:
            response = await client.post(
                "/api/handoff/result",
                json={
                    "handoff_id": "not-in-field",
                    "agent_name": "DSH",
                    "agent_response": "x",
                },
            )
        self.assertEqual(422, response.status_code)


if __name__ == "__main__":
    unittest.main()
