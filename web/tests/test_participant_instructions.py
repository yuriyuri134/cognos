"""测试参与说明动态生效与查询接口。"""

from __future__ import annotations

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
from server import AppConfig, create_app
from test_helpers import login_test_user


class RecordingRuntime:
    def __init__(self, prefix: str = "rec", thread_id: str = "thread-rec") -> None:
        self.prefix = prefix
        self.thread_id = thread_id
        self.developer_instructions: str | None = None
        self.last_message: str | None = None

    async def send(self, message: str) -> RuntimeReply:
        self.last_message = message
        return RuntimeReply(
            thread_id=self.thread_id,
            turn_id=f"turn-{self.prefix}-{len(message)}",
            text=f"{self.prefix} 回复：{message}",
        )


class ParticipantInstructionsDynamicTest(unittest.IsolatedAsyncioTestCase):
    def setUp(self) -> None:
        self.temporary_directory = tempfile.TemporaryDirectory()
        self.root = Path(self.temporary_directory.name)
        self.instructions_file = self.root / "participant_instructions.md"
        self.instructions_file.write_text("初始版本参与说明：请保持理性与克制。", encoding="utf-8")

        self.config = AppConfig(
            history_path=self.root / "vault" / "history.jsonl",
            thread_state_dir=self.root / "state",
            codex_cwd=self.root,
            participant_instructions_path=self.instructions_file,
        )

    def tearDown(self) -> None:
        self.temporary_directory.cleanup()

    async def test_get_instructions_endpoint(self) -> None:
        """验证 GET /api/instructions 接口返回当前说明内容、路径与长度。"""
        app = create_app(self.config, runtime=RecordingRuntime())
        async with AsyncClient(
            transport=ASGITransport(app=app), base_url="http://test"
        ) as client:
            anonymous = await client.get("/api/instructions")
            self.assertEqual(401, anonymous.status_code)
            await login_test_user(client)
            res = await client.get("/api/instructions")
            self.assertEqual(200, res.status_code)
            data = res.json()
            self.assertIn("content", data)
            self.assertIn("path", data)
            self.assertIn("length", data)
            self.assertEqual("初始版本参与说明：请保持理性与克制。", data["content"])
            self.assertEqual(len("初始版本参与说明：请保持理性与克制。"), data["length"])

    async def test_dynamic_instruction_reload_on_file_update(self) -> None:
        """验证磁盘文件修改后，GET /api/instructions 能动态返回最新说明，无需重启服务。"""
        app = create_app(self.config, runtime=RecordingRuntime())
        async with AsyncClient(
            transport=ASGITransport(app=app), base_url="http://test"
        ) as client:
            await login_test_user(client)
            # 1. 初始查询
            res1 = await client.get("/api/instructions")
            self.assertEqual(200, res1.status_code)
            self.assertEqual("初始版本参与说明：请保持理性与克制。", res1.json()["content"])

            # 2. 模拟外部修改 participant_instructions.md
            updated_text = "第二版参与说明：请深入代码库，积极进行跨模块重构与验证。"
            self.instructions_file.write_text(updated_text, encoding="utf-8")

            # 3. 再次查询，应立即反映更新
            res2 = await client.get("/api/instructions")
            self.assertEqual(200, res2.status_code)
            self.assertEqual(updated_text, res2.json()["content"])
            self.assertEqual(len(updated_text), res2.json()["length"])
