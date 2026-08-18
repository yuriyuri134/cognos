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
