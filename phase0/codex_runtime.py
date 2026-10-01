"""Cognos Phase 0 — Codex Runtime Adapter。

本文件只验证一件事：Cognos 能否创建并恢复一个本地 Codex thread。
它不是通用 LLM 网关，也不承载 Cognos 的本体数据模型。
"""

from __future__ import annotations

import json
import os
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Callable, Protocol


class RuntimeUnavailableError(RuntimeError):
    """Codex SDK、登录或运行时不可用。"""


class ThreadStateError(RuntimeError):
    """本地 thread 状态文件无法读取或写入。"""


@dataclass(frozen=True)
class RuntimeReply:
    """一次运行时发生的最小引用；正文只是该 turn 的最终文本投影。"""

    thread_id: str
    turn_id: str
    text: str
    model_used: str | None = None
    sources: list[dict[str, str]] | None = None
    requested_model: str | None = None


class RuntimeAdapter(Protocol):
    """Cognos 对参与者运行时的最小依赖面。"""

    async def send(self, message: str) -> RuntimeReply:
        """向运行时发送一条消息，并返回可恢复的 thread 标识。"""


class JsonThreadStore:
    """只保存运行时绑定信息，不保存对话正文或认证凭据。"""

    def __init__(self, path: Path) -> None:
        self.path = path

    def load(self) -> str | None:
        if not self.path.exists():
            return None

        try:
            payload = json.loads(self.path.read_text(encoding="utf-8"))
        except (OSError, json.JSONDecodeError) as exc:
            raise ThreadStateError(f"无法读取 thread 状态：{self.path}") from exc

        thread_id = payload.get("thread_id")
        if thread_id is None:
            return None
        if not isinstance(thread_id, str) or not thread_id.strip():
            raise ThreadStateError(f"thread_id 格式无效：{self.path}")
        return thread_id

    def save(self, thread_id: str) -> None:
        if not thread_id.strip():
            raise ThreadStateError("不能保存空的 thread_id")

        self.path.parent.mkdir(parents=True, exist_ok=True)
        temporary_path = self.path.with_suffix(self.path.suffix + ".tmp")
        payload = json.dumps({"thread_id": thread_id}, ensure_ascii=False, indent=2)

        try:
            temporary_path.write_text(payload + "\n", encoding="utf-8")
            os.replace(temporary_path, self.path)
        except OSError as exc:
            raise ThreadStateError(f"无法写入 thread 状态：{self.path}") from exc


def load_official_sdk() -> tuple[Any, Any]:
    """延迟导入官方 SDK，让单元测试不依赖网络安装。"""

    try:
        from openai_codex import AsyncCodex, Sandbox
    except ImportError as exc:
        raise RuntimeUnavailableError(
            "缺少 openai-codex。请先在 Phase 0 虚拟环境中安装 requirements.txt。"
        ) from exc
    return AsyncCodex, Sandbox


class CodexRuntimeAdapter:
    """通过官方 Python SDK 控制本地 Codex app-server。"""

    activity_input_scope = "fresh_thread_runtime_context_not_captured"

    def __init__(
        self,
        *,
        cwd: Path,
        state_store: JsonThreadStore,
        model: str | None = None,
        developer_instructions: str | None = None,
        sdk_loader: Callable[[], tuple[Any, Any]] = load_official_sdk,
    ) -> None:
        self.cwd = cwd.resolve()
        self.state_store = state_store
        self.model = model
        self.developer_instructions = developer_instructions
        self._sdk_loader = sdk_loader

    async def send(self, message: str) -> RuntimeReply:
        return await self._send(message)

    async def send_activity(self, message: str, *, on_request: Callable[[dict[str, Any]], None]) -> RuntimeReply:
        """Fresh ephemeral thread; ordinary chat state remains untouched.

        SDK arguments are observable, but app-server instructions and tool reads
        are not a complete model context. Advertise that distinction explicitly.
        """
        return await self._send(message, isolated=True, on_request=on_request)

    async def _send(self, message: str, *, isolated: bool = False,
                    on_request: Callable[[dict[str, Any]], None] | None = None) -> RuntimeReply:
        if not message.strip():
            raise ValueError("消息不能为空")
        if not self.cwd.exists():
            raise RuntimeUnavailableError(f"Codex 工作目录不存在：{self.cwd}")

        AsyncCodex, Sandbox = self._sdk_loader()
        stored_thread_id = None if isolated else self.state_store.load()

        try:
            async with AsyncCodex() as client:
                if stored_thread_id:
                    resume_options: dict[str, Any] = {
                        "cwd": str(self.cwd),
                        "sandbox": Sandbox.read_only,
                    }
                    if self.model:
                        resume_options["model"] = self.model
                    if self.developer_instructions:
                        resume_options["developer_instructions"] = self.developer_instructions
                    thread = await self._resume_thread(
                        client,
                        stored_thread_id,
                        **resume_options,
                    )
                else:
                    start_options: dict[str, Any] = {
                        "cwd": str(self.cwd),
                        "sandbox": Sandbox.read_only,
                    }
                    if self.model:
                        start_options["model"] = self.model
                    if self.developer_instructions:
                        start_options["developer_instructions"] = self.developer_instructions
                    if isolated:
                        start_options["ephemeral"] = True
                    if on_request:
                        on_request({"protocol": "codex_sdk", "operation": "thread_start",
                                    "history_scope": self.activity_input_scope,
                                    "payload": {**start_options, "sandbox": "read-only"}})
                    thread = await client.thread_start(**start_options)

                if on_request:
                    on_request({"protocol": "codex_sdk", "operation": "turn_run",
                                "history_scope": self.activity_input_scope,
                                "payload": {"thread_id": self._thread_id(thread, None), "input": message}})
                result = await thread.run(message)
                thread_id = self._thread_id(thread, stored_thread_id)
                if not isolated:
                    self.state_store.save(thread_id)
                return RuntimeReply(
                    thread_id=thread_id,
                    turn_id=self._turn_id(result),
                    text=self._response_text(result),
                    requested_model=self.model,
                )
        except RuntimeUnavailableError:
            raise
        except Exception as exc:
            raise RuntimeUnavailableError(
                "Codex 调用失败。请检查 `codex login status`、网络和本机运行时。"
            ) from exc

    @staticmethod
    async def _resume_thread(client: Any, thread_id: str, **options: Any) -> Any:
        """兼容 SDK 在稳定版迭代中可能使用的两个恢复方法名。"""

        for method_name in ("thread_resume", "resume_thread"):
            method = getattr(client, method_name, None)
            if method is not None:
                return await method(thread_id, **options)
        raise RuntimeUnavailableError("当前 openai-codex 版本没有可用的 thread 恢复方法")

    @staticmethod
    def _thread_id(thread: Any, fallback: str | None) -> str:
        for attribute in ("id", "thread_id"):
            value = getattr(thread, attribute, None)
            if isinstance(value, str) and value.strip():
                return value
        if fallback:
            return fallback
        raise RuntimeUnavailableError("Codex SDK 未返回可持久化的 thread_id")

    @staticmethod
    def _response_text(result: Any) -> str:
        for attribute in ("final_response", "finalResponse"):
            value = getattr(result, attribute, None)
            if isinstance(value, str):
                return value
        raise RuntimeUnavailableError("Codex SDK 未返回 final_response")

    @staticmethod
    def _turn_id(result: Any) -> str:
        """保留精确发生的锚点，避免最终文本冒充完整运行过程。"""

        for attribute in ("id", "turn_id", "turnId"):
            value = getattr(result, attribute, None)
            if isinstance(value, str) and value.strip():
                return value
        raise RuntimeUnavailableError("Codex SDK 未返回可引用的 turn_id")
