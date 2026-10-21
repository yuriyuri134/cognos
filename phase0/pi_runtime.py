"""Optional, conversation-only Pi RPC probe; Pi owns its session JSONL files.

参考协议：~/.claude/agent-base/understanding-first-protocol.md
"""

from __future__ import annotations

import asyncio
import hashlib
import json
import math
import os
from pathlib import Path
import shutil
import time
from dataclasses import dataclass
from typing import Any, Callable
import uuid

from codex_runtime import RuntimeReply, RuntimeUnavailableError, ThreadStateError


@dataclass(frozen=True)
class PiSessionState:
    thread_id: str
    session_path: str


class PiStateStore:
    """Only non-credential binding metadata, never Pi session contents."""

    def __init__(self, path: Path) -> None:
        self.path = path

    def load(self) -> PiSessionState | None:
        try:
            if not self.path.exists():
                return None
            data = json.loads(self.path.read_text(encoding="utf-8"))
            if not isinstance(data, dict) or not all(
                isinstance(data.get(key), str) and data[key].strip()
                for key in ("thread_id", "session_path")
            ):
                raise ValueError("invalid binding")
            return PiSessionState(data["thread_id"], data["session_path"])
        except (OSError, ValueError) as exc:
            raise ThreadStateError("无法读取 Pi session 状态") from exc

    def save(self, state: PiSessionState) -> None:
        try:
            self.path.parent.mkdir(parents=True, exist_ok=True)
            temporary = self.path.with_suffix(self.path.suffix + ".tmp")
            temporary.write_text(json.dumps({"thread_id": state.thread_id,
                                             "session_path": state.session_path}) + "\n",
                                 encoding="utf-8")
            os.replace(temporary, self.path)
        except OSError as exc:
            raise ThreadStateError("无法保存 Pi session 状态") from exc


def _executable(command: str) -> list[str]:
    """Resolve npm's Windows shim to Node directly: no shell or wrapper child."""
    resolved = shutil.which(command) or command
    if os.name == "nt" and Path(resolved).suffix.lower() in (".cmd", ".bat", ".ps1"):
        base = Path(resolved).parent
        node = str(base / "node.exe") if (base / "node.exe").is_file() else shutil.which("node")
        package_candidates = (
            base / "node_modules" / "@earendil-works" / "pi-coding-agent" / "dist" / "bundle" / "cli.js",
            base / "node_modules" / "@mariozechner" / "pi-coding-agent" / "dist" / "cli.js",
        )
        cli = next((candidate for candidate in package_candidates if candidate.is_file()), None)
        if not node or cli is None:
            raise RuntimeUnavailableError("Pi npm shim 无法解析；请配置 Pi 可执行文件路径")
        return [node, str(cli)]
    return [resolved]


class PiRuntimeAdapter:
    provider = "pi-rpc"
    capabilities = ["conversation"]
    activity_input_scope = "fresh_session_runtime_context_not_captured"
    turn_ref_kind = "adapter_generated"
    # A display name must never masquerade as an unknown model.
    model_identity_optional = True

    def __init__(self, *, cwd: Path, state_store: PiStateStore,
                 session_dir: Path | None = None, command: str = "pi",
                 timeout: float = 120.0, model: str | None = None,
                 developer_instructions: str | None = None,
                 subprocess_factory: Callable[..., Any] | None = None,
                 max_reply_chars: int = 2_000_000,
                 max_protocol_bytes: int = 16_000_000) -> None:
        self.cwd = cwd.resolve()
        self.state_store = state_store
        self.session_dir = (session_dir or state_store.path.parent / "pi-sessions").resolve()
        self.command = command
        self.timeout = float(timeout)
        if not math.isfinite(self.timeout) or self.timeout <= 0:
            raise ValueError("Pi timeout 必须是有限正数")
        if max_reply_chars <= 0 or max_protocol_bytes <= 0:
            raise ValueError("Pi 输出预算必须为正数")
        self.max_reply_chars = max_reply_chars
        self.max_protocol_bytes = max_protocol_bytes
        self.model = model or None
        self.developer_instructions = developer_instructions or (
            "理解优先协议：先明确你理解什么、有什么证据、不知道什么；再决定做什么。"
            "不要在没搞清现况的情况下执行 action。\n\n"
            "参考：~/.claude/agent-base/understanding-first-protocol.md"
        )
        self._spawn = subprocess_factory or asyncio.create_subprocess_exec
        self._lock = asyncio.Lock()

    async def send(self, message: str) -> RuntimeReply:
        async with self._lock:
            return await self._send(message, isolated=False)

    async def send_activity(self, message: str, *, on_request: Callable[[dict[str, Any]], None]) -> RuntimeReply:
        return await self._send(message, isolated=True, on_request=on_request)

    def _session_path(self, value: str) -> Path:
        path = Path(value).resolve()
        if not path.is_relative_to(self.session_dir) or path.suffix != ".jsonl":
            raise RuntimeUnavailableError("Pi session 路径不在配置的隔离目录内")
        return path

    async def _send(self, message: str, *, isolated: bool,
                    on_request: Callable[[dict[str, Any]], None] | None = None) -> RuntimeReply:
        if not message.strip():
            raise ValueError("消息不能为空")
        if not self.cwd.is_dir():
            raise RuntimeUnavailableError("Pi 工作目录不存在")
        stored = None if isolated else self.state_store.load()
        args = _executable(self.command) + ["--mode", "rpc", "--no-tools", "--no-extensions",
                                          "--no-skills", "--no-prompt-templates"]
        if isolated:
            args.append("--no-session")
        else:
            self.session_dir.mkdir(parents=True, exist_ok=True)
            args.extend(["--session-dir", str(self.session_dir)])
            if stored:
                path = self._session_path(stored.session_path)
                if not path.is_file():
                    raise RuntimeUnavailableError("Pi 持久 session 文件缺失；拒绝静默重建会话")
                args.extend(["--session", str(path)])
        if self.model:
            args.extend(["--model", self.model])
        # Instructions go over RPC as text, never as shell syntax or CLI commands.
        prompt_text = (self.developer_instructions + "\n\n" + message
                       if self.developer_instructions else message)
        turn_id = "turn_" + uuid.uuid4().hex
        prompt = {"id": turn_id, "type": "prompt", "message": prompt_text,
                  "expandPromptTemplates": False}
        if on_request:
            on_request({"protocol": "pi_rpc_jsonl", "command": list(args), "cwd": str(self.cwd),
                        "history_scope": "assembled_activity_only", "model": self.model,
                        "turn_id": turn_id, "turn_ref_kind": self.turn_ref_kind,
                        "payload": {"id": turn_id, "type": "prompt", "expandPromptTemplates": False,
                                    "message_sha256": hashlib.sha256(prompt_text.encode("utf-8")).hexdigest(),
                                    "message_chars": len(prompt_text), "message_omitted": True}})
        process = None
        stderr_task = None
        stderr_bytes = [0]
        started = time.monotonic()
        try:
            # Bound startup separately so a broken wrapper cannot consume the whole request.
            process = await asyncio.wait_for(
                self._spawn(*args, cwd=str(self.cwd), stdin=asyncio.subprocess.PIPE,
                            stdout=asyncio.subprocess.PIPE, stderr=asyncio.subprocess.PIPE,
                            limit=min(8 * 1024 * 1024, self.max_protocol_bytes)),
                min(self.timeout, 10.0),
            )
            stderr_task = asyncio.create_task(self._drain_stderr(process.stderr, stderr_bytes))
            remaining = self.timeout - (time.monotonic() - started)
            if remaining <= 0:
                raise asyncio.TimeoutError
            reply, state = await asyncio.wait_for(
                self._exchange(process, prompt, stored, isolated), remaining)
            if not isolated:
                self.state_store.save(state)
            return reply
        except asyncio.TimeoutError:
            raise RuntimeUnavailableError("Pi RPC 超时") from None
        except (OSError, ValueError, TypeError, AttributeError, NotImplementedError):
            # Do not expose raw process/provider errors: they may contain credentials.
            raise RuntimeUnavailableError("Pi RPC 不可用或协议无效（原始诊断已省略）") from None
        except RuntimeUnavailableError as exc:
            suffix = "；stderr 有诊断（内容已省略）" if stderr_bytes[0] else ""
            raise RuntimeUnavailableError(str(exc) + suffix) from None
        finally:
            if process is not None:
                cleanup = asyncio.create_task(self._close(process, stderr_task))
                try:
                    await asyncio.shield(cleanup)
                except asyncio.CancelledError:
                    await cleanup
                    raise

    @staticmethod
    async def _drain_stderr(stream: Any, count: list[int]) -> None:
        while chunk := await stream.read(8192):
            count[0] += len(chunk)

    @staticmethod
    async def _close(process: Any, stderr_task: Any) -> None:
        try:
            try:
                process.stdin.close()
            except (OSError, RuntimeError):
                pass  # A broken stdin must not prevent reaping the child.
            try:
                await asyncio.wait_for(process.wait(), 1.0)
            except asyncio.TimeoutError:
                try:
                    process.terminate()
                except ProcessLookupError:
                    pass
                try:
                    await asyncio.wait_for(process.wait(), 1.0)
                except asyncio.TimeoutError:
                    try:
                        process.kill()
                    except ProcessLookupError:
                        pass
                    try:
                        await asyncio.wait_for(process.wait(), 1.0)
                    except asyncio.TimeoutError:
                        # Broken children must not block the request forever.
                        pass
        finally:
            if stderr_task is not None:
                stderr_task.cancel()
                await asyncio.gather(stderr_task, return_exceptions=True)

    async def _exchange(self, process: Any, prompt: dict[str, Any],
                        stored: PiSessionState | None, isolated: bool) -> tuple[RuntimeReply, PiSessionState]:
        protocol_bytes = 0
        reply_chars = 0

        async def write(record: dict[str, Any]) -> None:
            process.stdin.write(json.dumps(record, ensure_ascii=False).encode("utf-8") + b"\n")
            await process.stdin.drain()

        async def read() -> dict[str, Any]:
            nonlocal protocol_bytes
            line = await process.stdout.readline()  # binary, strictly LF (not splitlines)
            protocol_bytes += len(line)
            if protocol_bytes > self.max_protocol_bytes:
                raise RuntimeUnavailableError("Pi RPC 输出超过协议预算")
            if not line:
                raise RuntimeUnavailableError("Pi 子进程退出或 stdout 关闭，未完成 RPC")
            if not line.endswith(b"\n"):
                raise RuntimeUnavailableError("Pi RPC 收到不完整 JSONL")
            record = json.loads(line)
            if not isinstance(record, dict):
                raise RuntimeUnavailableError("Pi RPC record 必须是对象")
            if record.get("type") == "response" and record.get("success") is not True:
                raise RuntimeUnavailableError("Pi RPC 返回错误 response（详情已省略）")
            return record

        state_id = "state_" + uuid.uuid4().hex
        await write({"id": state_id, "type": "get_state"})
        while True:
            record = await read()
            if record.get("type") == "response" and record.get("id") == state_id:
                if record.get("command") != "get_state" or not isinstance(record.get("data"), dict):
                    raise RuntimeUnavailableError("Pi get_state 响应无效")
                data = record["data"]
                break
        session_id = data.get("sessionId")
        if not isinstance(session_id, str) or not session_id.strip():
            raise RuntimeUnavailableError("Pi 未报告 session id")
        session_path = ""
        if not isolated:
            if not isinstance(data.get("sessionFile"), str) or not data["sessionFile"]:
                raise RuntimeUnavailableError("Pi 未报告持久 session 路径")
            session_path = str(self._session_path(data["sessionFile"]))
            if stored and (session_id != stored.thread_id or session_path != str(Path(stored.session_path).resolve())):
                raise RuntimeUnavailableError("Pi 未恢复指定的 session")
        state = PiSessionState(session_id, session_path)
        await write(prompt)
        acknowledged = False
        modern = False
        ended = False
        settled = False
        deltas: list[str] = []
        final_text: str | None = None
        model_used = None

        def assistant(message: Any) -> None:
            nonlocal final_text, model_used, reply_chars
            if not isinstance(message, dict) or message.get("role") != "assistant":
                return
            if message.get("stopReason") in ("error", "aborted") or message.get("errorMessage"):
                raise RuntimeUnavailableError("Pi 模型调用失败或中止（详情已省略）")
            content = message.get("content", [])
            if isinstance(content, list):
                if any(isinstance(part, dict) and part.get("type") == "toolCall" for part in content):
                    raise RuntimeUnavailableError("Pi 返回了被禁止的工具调用")
                text = "".join(part["text"] for part in content if isinstance(part, dict)
                               and part.get("type") == "text" and isinstance(part.get("text"), str))
                if text:
                    reply_chars += len(text)
                    if reply_chars > self.max_reply_chars:
                        raise RuntimeUnavailableError("Pi 回复超过字符预算")
                    final_text = text
            if isinstance(message.get("model"), str) and message["model"]:
                model_used = message["model"]

        while not (acknowledged and (settled or (ended and not modern))):
            record = await read()
            kind = record.get("type")
            if kind == "response":
                if record.get("id") == prompt["id"]:
                    if record.get("command") != "prompt":
                        raise RuntimeUnavailableError("Pi prompt 响应无效")
                    acknowledged = True
                    disposition = (record.get("data") or {}).get("disposition")
                    modern = disposition is not None
                    if disposition == "handled":
                        raise RuntimeUnavailableError("Pi prompt 未启动模型回复")
            elif kind == "message_start" and (record.get("message") or {}).get("role") == "assistant":
                deltas = []
                final_text = None
                model_used = None
            elif kind == "message_update":
                update = record.get("assistantMessageEvent", {})
                if not isinstance(update, dict):
                    raise RuntimeUnavailableError("Pi message_update 响应无效")
                if update.get("type") == "text_delta" and isinstance(update.get("delta"), str):
                    reply_chars += len(update["delta"])
                    if reply_chars > self.max_reply_chars:
                        raise RuntimeUnavailableError("Pi 回复超过字符预算")
                    deltas.append(update["delta"])
            elif kind == "message_end":
                assistant(record.get("message"))
            elif kind == "agent_end":
                for item in record.get("messages") or []:
                    assistant(item)
                ended = True
            elif kind == "agent_start":
                ended = False
            elif kind == "agent_settled":
                settled = True
            elif kind in ("tool_execution_start", "tool_execution_update", "tool_execution_end"):
                raise RuntimeUnavailableError("Pi 工具执行协议违反 conversation-only 边界")
        text = (final_text if final_text is not None else "".join(deltas)).strip()
        if not text:
            raise RuntimeUnavailableError("Pi 返回空回复")
        if process.returncode not in (None, 0):
            raise RuntimeUnavailableError("Pi 子进程异常退出")
        return RuntimeReply(thread_id=session_id, turn_id=prompt["id"], text=text,
                            model_used=model_used, requested_model=self.model), state
