"""不访问网络，验证 thread 创建、保存与跨进程恢复控制链。"""

from __future__ import annotations

import sys
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace


PHASE0_DIR = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(PHASE0_DIR))

from codex_runtime import CodexRuntimeAdapter, JsonThreadStore, ThreadStateError


class FakeSandbox:
    read_only = "read-only"


class FakeThread:
    def __init__(self, thread_id: str, calls: list[tuple]) -> None:
        self.id = thread_id
        self.calls = calls

    async def run(self, message: str) -> SimpleNamespace:
        self.calls.append(("run", self.id, message))
        return SimpleNamespace(
            id=f"turn:{self.id}:{message}",
            final_response=f"reply:{message}",
        )


class FakeAsyncCodex:
    calls: list[tuple] = []

    async def __aenter__(self) -> "FakeAsyncCodex":
        return self

    async def __aexit__(self, exc_type, exc, traceback) -> None:
        return None

    async def thread_start(self, **options) -> FakeThread:
        self.calls.append(("start", options))
        return FakeThread("thread-phase0", self.calls)

    async def thread_resume(self, thread_id: str, **options) -> FakeThread:
        self.calls.append(("resume", thread_id, options))
        return FakeThread(thread_id, self.calls)


def fake_sdk_loader():
    return FakeAsyncCodex, FakeSandbox


class CodexRuntimeAdapterTest(unittest.IsolatedAsyncioTestCase):
    def setUp(self) -> None:
        FakeAsyncCodex.calls = []
        self.temporary_directory = tempfile.TemporaryDirectory()
        self.root = Path(self.temporary_directory.name)
        self.state_path = self.root / "state" / "thread.json"

    def tearDown(self) -> None:
        self.temporary_directory.cleanup()

    async def test_creates_thread_and_saves_identifier(self) -> None:
        adapter = CodexRuntimeAdapter(
            cwd=self.root,
            state_store=JsonThreadStore(self.state_path),
            developer_instructions="保持整体目的",
            sdk_loader=fake_sdk_loader,
        )

        reply = await adapter.send("第一次")

        self.assertEqual("thread-phase0", reply.thread_id)
        self.assertEqual("turn:thread-phase0:第一次", reply.turn_id)
        self.assertEqual("reply:第一次", reply.text)
        self.assertEqual("thread-phase0", JsonThreadStore(self.state_path).load())
        self.assertEqual("start", FakeAsyncCodex.calls[0][0])
        self.assertEqual("read-only", FakeAsyncCodex.calls[0][1]["sandbox"])
        self.assertEqual(
            "保持整体目的",
            FakeAsyncCodex.calls[0][1]["developer_instructions"],
        )

    async def test_new_adapter_resumes_saved_thread(self) -> None:
        store = JsonThreadStore(self.state_path)
        store.save("thread-existing")
        adapter = CodexRuntimeAdapter(
            cwd=self.root,
            state_store=store,
            developer_instructions="保持整体目的",
            sdk_loader=fake_sdk_loader,
        )

        reply = await adapter.send("继续")

        self.assertEqual("thread-existing", reply.thread_id)
        self.assertEqual("turn:thread-existing:继续", reply.turn_id)
        self.assertEqual("resume", FakeAsyncCodex.calls[0][0])
        self.assertEqual("thread-existing", FakeAsyncCodex.calls[0][1])
        self.assertEqual("read-only", FakeAsyncCodex.calls[0][2]["sandbox"])
        self.assertEqual(
            "保持整体目的",
            FakeAsyncCodex.calls[0][2]["developer_instructions"],
        )
        self.assertEqual(("run", "thread-existing", "继续"), FakeAsyncCodex.calls[1])

    async def test_activity_uses_ephemeral_thread_and_preserves_chat_binding(self) -> None:
        store = JsonThreadStore(self.state_path)
        store.save("thread-existing")
        original = self.state_path.read_bytes()
        adapter = CodexRuntimeAdapter(cwd=self.root, state_store=store,
            developer_instructions="固定参与说明", sdk_loader=fake_sdk_loader)
        records = []
        def record(request):
            expected = "start" if request["operation"] == "thread_start" else "run"
            # The corresponding SDK operation has not happened yet.
            self.assertFalse(any(call[0] == expected for call in FakeAsyncCodex.calls))
            records.append(request)
        reply = await adapter.send_activity("仅本轮活动", on_request=record)
        self.assertNotEqual("thread-existing", reply.thread_id)
        self.assertEqual(original, self.state_path.read_bytes())
        self.assertEqual(["start", "run"], [c[0] for c in FakeAsyncCodex.calls])
        self.assertTrue(FakeAsyncCodex.calls[0][1]["ephemeral"])
        self.assertEqual("固定参与说明", records[0]["payload"]["developer_instructions"])
        self.assertEqual("仅本轮活动", records[1]["payload"]["input"])
        FakeAsyncCodex.calls = []
        await adapter.send("普通聊天接续")
        self.assertEqual(("resume", "thread-existing"), FakeAsyncCodex.calls[0][:2])

    async def test_activity_never_loads_even_a_corrupt_chat_binding(self) -> None:
        self.state_path.parent.mkdir(parents=True)
        self.state_path.write_text("invalid chat state", encoding="utf-8")
        adapter = CodexRuntimeAdapter(cwd=self.root, state_store=JsonThreadStore(self.state_path),
                                     sdk_loader=fake_sdk_loader)
        await adapter.send_activity("独立活动", on_request=lambda _: None)
        self.assertEqual("invalid chat state", self.state_path.read_text(encoding="utf-8"))

    async def test_activity_snapshot_failure_prevents_thread_creation(self) -> None:
        adapter = CodexRuntimeAdapter(cwd=self.root, state_store=JsonThreadStore(self.state_path),
                                     sdk_loader=fake_sdk_loader)
        def reject(_):
            raise OSError("snapshot failed")
        with self.assertRaises(RuntimeError):
            await adapter.send_activity("独立活动", on_request=reject)
        self.assertEqual([], FakeAsyncCodex.calls)
        self.assertFalse(self.state_path.exists())

    async def test_activity_turn_failure_preserves_chat_binding(self) -> None:
        store = JsonThreadStore(self.state_path)
        store.save("original-chat")
        class BrokenThread(FakeThread):
            async def run(self, message):
                raise ConnectionError("lost transport")
        class BrokenCodex(FakeAsyncCodex):
            async def thread_start(self, **options):
                return BrokenThread("new-thread", self.calls)
        adapter = CodexRuntimeAdapter(cwd=self.root, state_store=store,
            sdk_loader=lambda: (BrokenCodex, FakeSandbox))
        records = []
        with self.assertRaises(RuntimeError):
            await adapter.send_activity("独立活动", on_request=records.append)
        self.assertEqual("original-chat", store.load())
        self.assertEqual(["thread_start", "turn_run"], [r["operation"] for r in records])

    def test_rejects_corrupted_state(self) -> None:
        self.state_path.parent.mkdir(parents=True)
        self.state_path.write_text("not-json", encoding="utf-8")

        with self.assertRaises(ThreadStateError):
            JsonThreadStore(self.state_path).load()

    async def test_rejects_reply_without_turn_reference(self) -> None:
        class ThreadWithoutTurnId(FakeThread):
            async def run(self, message: str) -> SimpleNamespace:
                return SimpleNamespace(final_response=f"reply:{message}")

        class CodexWithoutTurnId(FakeAsyncCodex):
            async def thread_start(self, **options) -> FakeThread:
                return ThreadWithoutTurnId("thread-no-turn", self.calls)

        adapter = CodexRuntimeAdapter(
            cwd=self.root,
            state_store=JsonThreadStore(self.state_path),
            sdk_loader=lambda: (CodexWithoutTurnId, FakeSandbox),
        )

        with self.assertRaisesRegex(RuntimeError, "turn_id"):
            await adapter.send("不能压成无来源文本")


if __name__ == "__main__":
    unittest.main()
