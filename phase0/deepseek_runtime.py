"""Cognos — DeepSeek (opencode-go / zen go 套餐) Runtime Adapter。

通过 OpenAI-completions 兼容端点接入 DeepSeek V4 Flash/Pro，作为第一发生场内
可 @ 的持续参与者运行时。与 GeminiRuntimeAdapter 对称：持有独立状态文件、
跨服务重启恢复连续性。

凭据来源（按优先级）：
1. 环境变量 DEEPSEEK_API_KEY / DEEPSEEK_BASE_URL / DEEPSEEK_MODEL
2. `~/.dsh/.credentials.yaml`（DEEPSEEK_API_KEY: <key>）
3. `~/.pi/agent/auth.json` 的 opencode-go 条目

本适配器只做对话（不执行工具）；要让人工智能去真实改代码，仍走 E1-E5 Handoff。
"""

from __future__ import annotations

import asyncio
import json
import os
import re
import uuid
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any, Callable

import httpx

from codex_runtime import RuntimeReply, RuntimeUnavailableError, ThreadStateError


DEFAULT_BASE_URL = "https://opencode.ai/zen/go/v1"
DEFAULT_MODEL = "deepseek-v4-flash"
DEFAULT_MODEL_NAME = "DeepSeek V4 Flash"


@dataclass
class DeepSeekConversationState:
    thread_id: str
    contents: list[dict[str, Any]] = field(default_factory=list)


class DeepSeekStateStore:
    """持久化 DeepSeek 会话流，支持跨服务重启恢复连续性。"""

    def __init__(self, path: Path) -> None:
        self.path = path

    def load(self) -> DeepSeekConversationState | None:
        if not self.path.exists():
            return None
        try:
            payload = json.loads(self.path.read_text(encoding="utf-8"))
            thread_id = payload.get("thread_id")
            if not thread_id or not isinstance(thread_id, str):
                return None
            contents = payload.get("contents", [])
            return DeepSeekConversationState(thread_id=thread_id, contents=contents)
        except (OSError, json.JSONDecodeError) as exc:
            raise ThreadStateError(f"无法读取 DeepSeek thread 状态：{self.path}") from exc

    def save(self, state: DeepSeekConversationState) -> None:
        self.path.parent.mkdir(parents=True, exist_ok=True)
        temporary_path = self.path.with_suffix(self.path.suffix + ".tmp")
        payload = json.dumps(
            {"thread_id": state.thread_id, "contents": state.contents},
            ensure_ascii=False,
            indent=2,
        )
        try:
            temporary_path.write_text(payload + "\n", encoding="utf-8")
            os.replace(temporary_path, self.path)
        except OSError as exc:
            raise ThreadStateError(f"无法写入 DeepSeek thread 状态：{self.path}") from exc


def _read_credentials_yaml(key_name: str = "DEEPSEEK_API_KEY") -> str | None:
    """解析 `~/.dsh/.credentials.yaml` 中的 `KEY: <value>`。"""

    path = Path.home() / ".dsh" / ".credentials.yaml"
    if not path.exists():
        return None
    try:
        text = path.read_text(encoding="utf-8")
    except OSError:
        return None
    match = re.search(rf"^\s*{re.escape(key_name)}\s*:\s*[\"']?([^\s\"'#]+)", text, re.M)
    if not match:
        return None
    value = match.group(1).strip().strip('"').strip("'")
    return value or None


def _read_pi_auth(key_name: str = "opencode-go") -> str | None:
    """读取 `~/.pi/agent/auth.json` 中指定 provider 的 api_key。"""

    path = Path.home() / ".pi" / "agent" / "auth.json"
    if not path.exists():
        return None
    try:
        payload = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return None
    entry = payload.get(key_name)
    if isinstance(entry, dict) and entry.get("type") == "api_key":
        key = str(entry.get("key") or "").strip()
        return key or None
    return None


def load_deepseek_config() -> dict[str, str] | None:
    """解析 opencode-go 凭据与模型配置；无凭据时返回 None。"""

    api_key = os.environ.get("DEEPSEEK_API_KEY", "").strip()
    if not api_key:
        api_key = _read_credentials_yaml() or ""
    if not api_key:
        api_key = _read_pi_auth() or ""
    if not api_key:
        return None

    base_url = os.environ.get("DEEPSEEK_BASE_URL", "").strip() or DEFAULT_BASE_URL
    model = os.environ.get("DEEPSEEK_MODEL", "").strip() or DEFAULT_MODEL
    return {
        "api_key": api_key,
        "base_url": base_url.rstrip("/"),
        "model": model,
        "model_name": os.environ.get("DEEPSEEK_MODEL_NAME", "").strip() or DEFAULT_MODEL_NAME,
    }


def list_opencode_go_models() -> list[dict[str, str]]:
    """读取现有 OpenCode Go 配置里的可用模型（不硬编码模型清单）。

    解析 `~/.dsh/settings.yaml` 中 opencode-go 块的 models 段（- id / name 对）。
    """

    path = Path.home() / ".dsh" / "settings.yaml"
    if not path.exists():
        return [{"id": DEFAULT_MODEL, "name": DEFAULT_MODEL_NAME}]
    try:
        lines = path.read_text(encoding="utf-8").splitlines()
    except OSError:
        return [{"id": DEFAULT_MODEL, "name": DEFAULT_MODEL_NAME}]

    # 定位 opencode-go 块
    start = None
    block_indent = None
    for i, line in enumerate(lines):
        stripped = line.strip()
        if stripped.startswith("opencode-go:"):
            start = i
            block_indent = len(line) - len(line.lstrip())
            break
    if start is None:
        return [{"id": DEFAULT_MODEL, "name": DEFAULT_MODEL_NAME}]

    models: list[dict[str, str]] = []
    current_id: str | None = None
    for line in lines[start + 1 :]:
        if not line.strip():
            continue
        indent = len(line) - len(line.lstrip())
        if indent <= block_indent:
            break
        stripped = line.strip()
        if stripped.startswith("- id:"):
            current_id = stripped[len("- id:") :].strip().strip("'\"")
        elif stripped.startswith("name:") and current_id:
            name = stripped[len("name:") :].strip().strip("'\"")
            models.append({"id": current_id, "name": name})
            current_id = None
    if not models:
        return [{"id": DEFAULT_MODEL, "name": DEFAULT_MODEL_NAME}]
    return models


class DeepSeekRuntimeAdapter:
    """OpenAI-completions 兼容端点适配器；只做对话连续性，不做工具执行。"""

    def __init__(
        self,
        *,
        api_key: str,
        state_store: DeepSeekStateStore,
        base_url: str = DEFAULT_BASE_URL,
        model: str = DEFAULT_MODEL,
        model_name: str = DEFAULT_MODEL_NAME,
        developer_instructions: str | None = None,
        timeout: float = 120.0,
    ) -> None:
        self.api_key = api_key.strip()
        self.state_store = state_store
        self.base_url = base_url.rstrip("/")
        self.model = model
        self.model_name = model_name
        # The historical class name is retained for compatibility, but the
        # wire protocol is OpenAI-compatible and may serve any model.
        self.provider = os.environ.get("COGNOS_RUNTIME_PROVIDER", "openai-compatible").strip() or "openai-compatible"
        self.capabilities = ["conversation"]
        self.reasoning_effort = os.environ.get("DEEPSEEK_REASONING_EFFORT", "").strip() or None
        self.developer_instructions = developer_instructions
        self.timeout = float(os.environ.get("DEEPSEEK_TIMEOUT", str(timeout)))
        self._cached_state: DeepSeekConversationState | None = None

    def _get_or_create_state(self) -> DeepSeekConversationState:
        if self._cached_state is None:
            self._cached_state = self.state_store.load()
            if self._cached_state is None:
                self._cached_state = DeepSeekConversationState(
                    thread_id=f"deepseek_{uuid.uuid4()}"
                )
                self.state_store.save(self._cached_state)
        return self._cached_state

    async def send(self, message: str) -> RuntimeReply:
        return await self._send(message)

    async def send_activity(self, message: str, *, on_request: Callable[[dict[str, Any]], None]) -> RuntimeReply:
        """Use assembled activity context without reading/writing chat continuity."""
        return await self._send(message, isolated=True, on_request=on_request)

    async def _send(self, message: str, *, isolated: bool = False,
                    on_request: Callable[[dict[str, Any]], None] | None = None) -> RuntimeReply:
        if not message.strip():
            raise ValueError("消息不能为空")
        if not self.api_key:
            raise RuntimeUnavailableError("未配置 DeepSeek (opencode-go) API Key")

        # 先保存用户原文，避免被下面解析响应的局部变量覆盖（历史 bug）
        user_text = message

        state = (DeepSeekConversationState(thread_id=f"activity_{uuid.uuid4()}")
                 if isolated else self._get_or_create_state())

        payload: dict[str, Any] = {
            "model": self.model,
            "messages": [],
            "stream": False,
        }
        if self.developer_instructions:
            payload["messages"].append(
                {"role": "system", "content": self.developer_instructions}
            )
        payload["messages"].extend(list(state.contents))
        payload["messages"].append({"role": "user", "content": user_text})

        reasoning_effort = self.reasoning_effort
        if reasoning_effort:
            payload["reasoning_effort"] = reasoning_effort
        max_tokens = os.environ.get("DEEPSEEK_MAX_TOKENS", "").strip()
        if max_tokens and max_tokens.isdigit():
            payload["max_tokens"] = int(max_tokens)

        url = f"{self.base_url}/chat/completions"
        headers = {
            "Authorization": f"Bearer {self.api_key}",
            "Content-Type": "application/json",
            # OpenCode Go 需要显式的 provider session 才能路由请求。
            # 这里沿用 Cognos 为该 participant 保存的 continuity id；
            # 不把 session id 复制进 occurrence 或结果正文。
            "x-opencode-session": state.thread_id,
        }

        response: httpx.Response | None = None
        last_error: Exception | None = None
        for attempt in range(2):
            if on_request:
                on_request({"protocol": "chat_completions", "model": self.model,
                            "attempt": attempt + 1, "history_scope": "assembled_activity_only",
                            "payload": json.loads(json.dumps(payload))})
            try:
                async with httpx.AsyncClient(timeout=self.timeout) as client:
                    response = await client.post(url, json=payload, headers=headers)
                if response.status_code == 200:
                    break
                if response.status_code in (429, 500, 502, 503, 504):
                    await asyncio.sleep(0.8 * (attempt + 1))
                    continue
                break
            except httpx.RequestError as exc:
                last_error = exc
                await asyncio.sleep(0.8 * (attempt + 1))
                continue

        if response is None:
            detail = repr(last_error) if last_error else "多次尝试无响应（网络/代理/DNS 层）"
            raise RuntimeUnavailableError(
                f"DeepSeek 网络连接失败（url={url}，timeout={self.timeout:.0f}s）：{detail}"
            )
        if response.status_code != 200:
            snippet = (response.text or "")[:500].replace("\n", " ")
            raise RuntimeUnavailableError(
                f"DeepSeek (opencode-go) 返回 HTTP {response.status_code}（url={url}）：{snippet or '(空响应体)'}"
            )

        try:
            data = response.json()
            choices = data.get("choices") or []
            if not choices:
                raise RuntimeUnavailableError("DeepSeek 未返回有效 choices")
            api_message = choices[0].get("message") or {}
            reply_text = str(api_message.get("content") or "").strip()
            if not reply_text:
                # 部分推理模型只把可见正文放进 content；空则取 reasoning 摘要兜底
                reasoning = api_message.get("reasoning_content") or api_message.get("reasoning") or ""
                reply_text = str(reasoning).strip()
            if not reply_text:
                raise RuntimeUnavailableError("DeepSeek candidate content 为空")
        except RuntimeUnavailableError:
            raise
        except (ValueError, KeyError, IndexError) as exc:
            raise RuntimeUnavailableError("解析 DeepSeek 响应结构失败") from exc

        turn_id = f"turn_{uuid.uuid4()}"

        if not isolated:
            state.contents.append({"role": "user", "content": user_text})
            state.contents.append({"role": "assistant", "content": reply_text})
            self.state_store.save(state)

        return RuntimeReply(
            thread_id=state.thread_id,
            turn_id=turn_id,
            text=reply_text,
            model_used=data.get("model"),
            requested_model=self.model,
        )
