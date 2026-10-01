"""Offline Pi RPC subprocess doubles; never invoke Pi, network, or auth files."""

import asyncio
from contextlib import contextmanager
import importlib.util
import json
import os
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import AsyncMock, patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "web"))

from codex_runtime import RuntimeUnavailableError, ThreadStateError
from pi_runtime import PiRuntimeAdapter, PiStateStore
from runtime_profile import build_runtime_profile


def delta(text):
    return {"type": "message_update", "assistantMessageEvent": {"type": "text_delta", "delta": text}}


def answer(text="reply", **extra):
    return {"role": "assistant", "content": [{"type": "text", "text": text}], **extra}


class FakeProcess:
    def __init__(self, factory, args):
        self.factory = factory
        self.args = args
        self.stdin = self
        self.stdout = asyncio.StreamReader()
        self.stderr = asyncio.StreamReader()
        self.returncode = None
        self.closed = False
        self.terminated = False
        self.killed = False
        self.waited = False
        self.writes = []
        self.done = asyncio.Event()
        self.stderr.feed_data(b"Authorization: Bearer credential-sentinel\n")

    def emit(self, record):
        self.stdout.feed_data(json.dumps(record, ensure_ascii=False).encode("utf-8") + b"\n")

    def write(self, data):
        self.writes.append(data)
        command = json.loads(data)
        kind = command["type"]
        response = {"id": command["id"], "type": "response", "command": kind, "success": True}
        if kind == "get_state":
            if self.factory.state_override is not None:
                state = self.factory.state_override
            elif "--no-session" in self.args:
                state = {"sessionId": "ephemeral-" + str(len(self.factory.processes))}
            else:
                directory = Path(self.args[self.args.index("--session-dir") + 1])
                path = directory / "session.jsonl"
                # Simulated CLI owns this file; the adapter must never edit it.
                if not path.exists():
                    path.write_bytes(b"pi-owned-session-sentinel\n")
                state = {"sessionId": "persistent-id", "sessionFile": str(path)}
            self.emit({**response, "data": state})
        else:
            if self.factory.modern:
                response["data"] = {"disposition": "started"}
            if self.factory.ack_first:
                self.emit(response)
            for record in self.factory.events:
                if record == "error":
                    self.emit({**response, "success": False, "error": "credential-sentinel"})
                elif record == "eof":
                    self.returncode = 7
                    self.stdout.feed_eof()
                    self.done.set()
                elif isinstance(record, bytes):
                    self.stdout.feed_data(record)
                    self.stdout.feed_eof()
                else:
                    self.emit(record)
            if not self.factory.ack_first:
                self.emit(response)

    async def drain(self):
        await asyncio.sleep(0)

    def close(self):
        self.closed = True
        if not self.factory.stubborn:
            self.returncode = self.returncode or 0
            self.done.set()
        self.stderr.feed_eof()

    async def wait(self):
        if self.factory.stubborn and not self.killed:
            # Simulate expiry without spending wall time on shutdown tests.
            raise asyncio.TimeoutError()
        await self.done.wait()
        self.waited = True
        return self.returncode

    def terminate(self):
        self.terminated = True

    def kill(self):
        self.killed = True
        self.returncode = -9
        self.done.set()


class Factory:
    def __init__(self, events=None, *, modern=True, ack_first=True, stubborn=False, state_override=None):
        self.events = events if events is not None else [delta("reply"), {"type": "agent_settled"}]
        self.modern = modern
        self.ack_first = ack_first
        self.stubborn = stubborn
        self.state_override = state_override
        self.processes = []
        self.kwargs = []

    async def __call__(self, *args, **kwargs):
        self.kwargs.append(kwargs)
        process = FakeProcess(self, args)
        self.processes.append(process)
        return process


class PiRuntimeTest(unittest.IsolatedAsyncioTestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name).resolve()
        self.store = PiStateStore(self.root / "state" / "pi_thread.json")

    def adapter(self, factory=None, **kwargs):
        return PiRuntimeAdapter(cwd=self.root, state_store=self.store,
                                subprocess_factory=factory or Factory(), **kwargs)

    async def test_strict_lf_deltas_response_correlation_and_no_tools(self):
        text = "你好\u2028line\u2029paragraph\nend"
        factory = Factory([
            {"type": "response", "id": "unrelated", "command": "prompt", "success": True},
            delta(text[:3]), delta(text[3:]), {"type": "agent_settled"}], ack_first=False)
        reply = await self.adapter(factory).send(text)
        self.assertEqual(text, reply.text)
        self.assertEqual("persistent-id", reply.thread_id)
        self.assertTrue(reply.turn_id.startswith("turn_"))
        self.assertIsNone(reply.model_used)
        self.assertIsNone(reply.requested_model)
        proc = factory.processes[0]
        for raw in proc.writes:
            self.assertTrue(raw.endswith(b"\n"))
            self.assertEqual(1, raw.count(b"\n"))
            self.assertNotIn(b"\r", raw)
        self.assertIn(b"\xe2\x80\xa8", proc.writes[-1])
        self.assertIn("--no-tools", proc.args)
        self.assertIn("--no-extensions", proc.args)
        self.assertFalse(factory.kwargs[0].get("shell", False))
        self.assertEqual(str(self.root), factory.kwargs[0]["cwd"])
        self.assertTrue(proc.closed and proc.waited)

    async def test_modern_waits_after_agent_end_for_settled(self):
        factory = Factory([delta("partial"), {"type": "agent_end"},
                           {"type": "agent_start"},
                           {"type": "message_start", "message": {"role": "assistant"}},
                           delta("final"), {"type": "message_end", "message": answer("final", model="actual-model")},
                           {"type": "agent_settled"}])
        reply = await self.adapter(factory, model="requested-model").send("hello")
        self.assertEqual("final", reply.text)
        self.assertEqual("actual-model", reply.model_used)
        self.assertEqual("requested-model", reply.requested_model)
        self.assertIn("--model", factory.processes[0].args)

    async def test_legacy_agent_end_and_final_text_not_duplicated(self):
        factory = Factory([delta("reply"), {"type": "agent_end", "messages": [answer()]}], modern=False)
        self.assertEqual("reply", (await self.adapter(factory).send("hello")).text)

    async def test_crlf_input_and_no_provider_as_model(self):
        factory = Factory([delta("reply"), b'{"type":"agent_settled"}\r\n'],
                          state_override={"sessionId": "ephemeral", "model": {"provider": "some-provider"}})
        reply = await self.adapter(factory).send_activity("hi", on_request=lambda request: None)
        self.assertEqual("reply", reply.text)
        self.assertIsNone(reply.model_used)

    async def test_broken_pipe_still_reaps_child(self):
        factory = Factory()
        original = factory.__call__

        async def spawn(*args, **kwargs):
            proc = await original(*args, **kwargs)
            proc.drain = AsyncMock(side_effect=BrokenPipeError("credential-sentinel"))
            return proc

        with self.assertRaises(RuntimeUnavailableError):
            await self.adapter(spawn).send("hi")
        self.assertTrue(factory.processes[0].closed and factory.processes[0].waited)

    async def test_callback_failure_happens_before_subprocess_start(self):
        factory = Factory()

        def fail(request):
            raise RuntimeError("capture failed")

        with self.assertRaisesRegex(RuntimeError, "capture failed"):
            await self.adapter(factory).send_activity("hi", on_request=fail)
        self.assertEqual([], factory.processes)

    async def test_empty_message_never_starts_subprocess(self):
        factory = Factory()
        with self.assertRaises(ValueError):
            await self.adapter(factory).send(" \n ")
        self.assertEqual([], factory.processes)

    async def test_modern_agent_end_alone_times_out(self):
        factory = Factory([delta("partial"), {"type": "agent_end"}])
        with self.assertRaisesRegex(RuntimeUnavailableError, "超时"):
            await self.adapter(factory, timeout=.02).send("hello")
        self.assertTrue(factory.processes[0].closed)
        self.assertFalse(self.store.path.exists())

    async def test_persistent_binding_survives_adapter_recreation_activity_never_touches_it(self):
        factory = Factory()
        adapter = self.adapter(factory)
        first = await adapter.send("first")
        before = self.store.path.read_bytes()
        session = Path(self.store.load().session_path)
        session_before = session.read_bytes()
        captures = []
        with patch.object(self.store, "load", side_effect=AssertionError("activity read chat")), \
             patch.object(self.store, "save", side_effect=AssertionError("activity wrote chat")):
            activity = await adapter.send_activity("credential-sentinel", on_request=captures.append)
        last = await self.adapter(factory).send("second")
        self.assertEqual(first.thread_id, last.thread_id)
        self.assertNotEqual(first.turn_id, last.turn_id)
        self.assertNotEqual(first.thread_id, activity.thread_id)
        self.assertEqual(before, self.store.path.read_bytes())
        self.assertEqual(session_before, session.read_bytes())
        self.assertEqual({"thread_id", "session_path"}, set(json.loads(before)))
        self.assertIn("--session", factory.processes[-1].args)
        ephemeral_args = factory.processes[1].args
        self.assertIn("--no-session", ephemeral_args)
        self.assertNotIn("--session", ephemeral_args)
        self.assertNotIn("--session-dir", ephemeral_args)
        self.assertNotIn("credential-sentinel", json.dumps(captures) + before.decode())
        self.assertEqual("pi_rpc_jsonl", captures[0]["protocol"])
        self.assertEqual(activity.turn_id, captures[0]["payload"]["id"])
        self.assertTrue(captures[0]["payload"]["message_omitted"])

    async def test_fresh_activity_creates_no_state_or_session_directory(self):
        adapter = self.adapter()
        await adapter.send_activity("hi", on_request=lambda request: None)
        self.assertFalse(self.store.path.parent.exists())

    async def test_errors_are_safe_and_process_is_closed(self):
        scenarios = [
            ["error"], ["eof"], [{"type": "agent_settled"}],
            [b"not json\n"], [b'{"type":"agent_settled"}'], [b"[]\n"],
            [{"type": "message_update", "assistantMessageEvent": []}],
            [{"type": "message_update", "assistantMessageEvent": "invalid"}],
            [{"type": "message_end", "message": answer("partial", stopReason="error", errorMessage="credential-sentinel")}],
            [{"type": "message_end", "message": answer("partial", stopReason="aborted")}],
            [{"type": "tool_execution_start"}],
        ]
        for events in scenarios:
            with self.subTest(events=events):
                factory = Factory(events)
                with self.assertRaises(RuntimeUnavailableError) as caught:
                    await self.adapter(factory, timeout=.1).send_activity("hi", on_request=lambda request: None)
                self.assertNotIn("credential-sentinel", str(caught.exception))
                self.assertTrue(factory.processes[0].closed and factory.processes[0].waited)

    async def test_timeout_terminates_and_kills_stubborn_child(self):
        factory = Factory([], stubborn=True)
        with self.assertRaisesRegex(RuntimeUnavailableError, "超时"):
            await self.adapter(factory, timeout=.01).send("hi")
        proc = factory.processes[0]
        self.assertTrue(proc.closed and proc.terminated and proc.killed and proc.waited)

    async def test_cancellation_reaps_child(self):
        factory = Factory([])
        task = asyncio.create_task(self.adapter(factory).send("hi"))
        while not factory.processes or len(factory.processes[0].writes) < 2:
            await asyncio.sleep(0)
        task.cancel()
        with self.assertRaises(asyncio.CancelledError):
            await task
        self.assertTrue(factory.processes[0].closed and factory.processes[0].waited)

    async def test_missing_executable_is_lazy_unavailable(self):
        factory = AsyncMock(side_effect=FileNotFoundError("credential-sentinel"))
        adapter = self.adapter(factory)
        factory.assert_not_called()
        with self.assertRaises(RuntimeUnavailableError) as caught:
            await adapter.send("hi")
        self.assertNotIn("credential-sentinel", str(caught.exception))

    async def test_outside_session_directory_is_rejected(self):
        factory = Factory(state_override={"sessionId": "id", "sessionFile": str(self.root / "outside.jsonl")})
        with self.assertRaisesRegex(RuntimeUnavailableError, "隔离目录"):
            await self.adapter(factory).send("hi")
        self.assertEqual(1, len(factory.processes[0].writes))
        self.assertFalse(self.store.path.exists())

    async def test_missing_saved_session_does_not_silently_restart(self):
        await self.adapter().send("hi")
        # Delete only a temporary fixture, never a real Pi session.
        Path(self.store.load().session_path).unlink()
        factory = Factory()
        with self.assertRaisesRegex(RuntimeUnavailableError, "文件缺失"):
            await self.adapter(factory).send("hi")
        self.assertEqual([], factory.processes)

    async def test_session_identity_mismatch_rejected_before_prompt(self):
        await self.adapter().send("hi")
        saved = self.store.load()
        factory = Factory(state_override={"sessionId": "wrong", "sessionFile": saved.session_path})
        with self.assertRaisesRegex(RuntimeUnavailableError, "未恢复"):
            await self.adapter(factory).send("hi")
        self.assertEqual(1, len(factory.processes[0].writes))
        self.assertEqual(saved, self.store.load())

    async def test_concurrent_sends_serialize_and_resume(self):
        factory = Factory()
        adapter = self.adapter(factory)
        first, second = await asyncio.gather(adapter.send("one"), adapter.send("two"))
        self.assertEqual(first.thread_id, second.thread_id)
        self.assertIn("--session", factory.processes[1].args)

    def test_profile_unknown_model_and_adapter_turn_ref(self):
        profile = build_runtime_profile(self.adapter(), display_name="Pi")
        self.assertIsNone(profile["model"])
        self.assertEqual(["conversation"], profile["capabilities"])
        self.assertEqual("adapter_generated", profile["turn_ref_kind"])
        self.assertNotIn("auth", json.dumps(profile))

    def test_corrupt_state_fails_without_replacing(self):
        self.store.path.parent.mkdir()
        self.store.path.write_text("[]", encoding="utf-8")
        with self.assertRaises(ThreadStateError):
            self.store.load()
        self.assertEqual("[]", self.store.path.read_text())


class PiRegistrationTest(unittest.IsolatedAsyncioTestCase):
    @contextmanager
    def isolated_server(self, root):
        # server.py constructs a default app at import time. Load an isolated
        # module with temporary paths and prevent even reading local credentials.
        real_read = Path.read_text

        def safe_read(path, *args, **kwargs):
            if path.name == ".env":
                return ""
            return real_read(path, *args, **kwargs)

        spec = importlib.util.spec_from_file_location("pi_server_test", Path(__file__).resolve().parents[2] / "web" / "server.py")
        module = importlib.util.module_from_spec(spec)
        with patch.dict(sys.modules, {"pi_server_test": module}), \
             patch.object(Path, "read_text", safe_read), \
             patch("deepseek_runtime.load_deepseek_config", return_value=None), \
             patch("deepseek_runtime.list_opencode_go_models", return_value=[]), \
             patch.dict("os.environ", {"COGNOS_PI_ENABLED": "0", "COGNOS_EXTRA_PARTICIPANTS": "",
                                       "COGNOS_THREAD_STATE_DIR": str(root / "bootstrap-state"),
                                       "COGNOS_VAULT_PATH": str(root / "vault"),
                                       "COGNOS_ORG_DB_PATH": str(root / "bootstrap-state" / "org.db"),
                                       "COGNOS_INQUIRY_DB_PATH": str(root / "bootstrap-state" / "inquiry.db"),
                                       "COGNOS_ACTIVITY_DB_PATH": str(root / "bootstrap-state" / "activity.db"),
                                       "COGNOS_FRAME_SOURCES": ""}):
            spec.loader.exec_module(module)
            yield module

    async def test_opt_in_missing_executable_and_model_metadata(self):
        from httpx import ASGITransport, AsyncClient
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            with self.isolated_server(root) as server:
                await self.check_registration(server, root, ASGITransport, AsyncClient)

    async def check_registration(self, server, root, ASGITransport, AsyncClient):
        AppConfig, create_app = server.AppConfig, server.create_app
        instructions = root / "instructions.md"
        instructions.write_text("Test instructions", encoding="utf-8")
        config = AppConfig(history_path=root / "vault" / "first.jsonl", thread_state_dir=root / "state",
                           codex_cwd=root, participant_instructions_path=instructions)
        with patch.dict(os.environ):
            os.environ.pop("COGNOS_PI_ENABLED", None)
            self.assertNotIn("pi", create_app(config).state.participants)
        with patch.dict("os.environ", {"COGNOS_PI_ENABLED": "1", "COGNOS_PI_CWD": str(root),
                                      "COGNOS_PI_COMMAND": "missing-pi", "COGNOS_PI_MODEL": ""}):
            app = create_app(config)
        pi = app.state.participants["pi"]
        self.assertEqual("Pi", pi.name)
        self.assertEqual(root / "state" / "pi-sessions", pi.runtime.session_dir)
        pi.runtime._spawn = AsyncMock(side_effect=FileNotFoundError())
        async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
            models = (await client.get("/api/models")).json()["participants"]
            self.assertIsNone(next(item for item in models if item["id"] == "pi")["requested_model"])
            response = await client.post("/api/messages", json={"target": "pi", "content": "hi"})
            self.assertEqual(502, response.status_code)
        self.assertFalse((root / "state" / "pi_thread.json").exists())
        # Successful conversation and real ActivityService duck typing use
        # the same adapter, but only the conversation persists its binding.
        factory = Factory()
        pi.runtime._spawn = factory
        async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
            response = await client.post("/api/messages", json={"target": "pi", "content": "hi"})
            self.assertEqual(200, response.status_code, response.text)
            ref = response.json()["reply"]["runtime_ref"]
            self.assertEqual("persistent-id", ref["thread_id"])
            self.assertTrue(ref["turn_id"].startswith("turn_"))
            ordinary = pi.runtime.state_store.path.read_bytes()
            org = (await client.post("/api/orgs", json={"name": "Pi test"})).json()["organization"]
            project = (await client.post(f"/api/orgs/{org['id']}/projects", json={"title": "Pi test"})).json()["project"]
            activity = (await client.post(f"/api/projects/{project['id']}/activities", json={})).json()["activity"]
            factory.events = [delta(json.dumps({"message": "isolated", "pause": {"reason": "done"}})),
                              {"type": "agent_settled"}]
            continued = await client.post(f"/api/activities/{activity['id']}/continue", json={
                "revision": activity["revision"], "request_id": "pi-activity", "target": "pi"})
            self.assertEqual(200, continued.status_code, continued.text)
            event = continued.json()["run"]["events"][0]
            self.assertIsNone(event["runtime"]["requested_model"])
            self.assertIsNone(event["runtime"]["actual_model"])
            self.assertTrue(event["runtime"]["runtime_thread_id"].startswith("ephemeral-"))
            self.assertTrue(event["runtime"]["runtime_turn_id"].startswith("turn_"))
            self.assertEqual(ordinary, pi.runtime.state_store.path.read_bytes())
            self.assertIn("--no-session", factory.processes[-1].args)
