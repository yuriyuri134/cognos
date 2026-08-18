"""Cognos — Gemini Runtime Adapter。

通过 Google Gemini API 实现对外部模型的直接接入，作为 Codex 的对称可选身体。
不污染 Cognos 发生层，只遵循 RuntimeAdapter 协议。
"""

from __future__ import annotations

import asyncio
import json
import os
import uuid
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any

import httpx

from codex_runtime import RuntimeAdapter, RuntimeReply, RuntimeUnavailableError, ThreadStateError


@dataclass
class GeminiConversationState:
    thread_id: str
    contents: list[dict[str, Any]] = field(default_factory=list)


class GeminiStateStore:
    """持久化保存 Gemini 会话流，支持跨服务重启恢复连续性。"""

    def __init__(self, path: Path) -> None:
        self.path = path

    def load(self) -> GeminiConversationState | None:
        if not self.path.exists():
            return None
        try:
            payload = json.loads(self.path.read_text(encoding="utf-8"))
            thread_id = payload.get("thread_id")
            if not thread_id or not isinstance(thread_id, str):
                return None
            contents = payload.get("contents", [])
            return GeminiConversationState(thread_id=thread_id, contents=contents)
        except (OSError, json.JSONDecodeError) as exc:
            raise ThreadStateError(f"无法读取 Gemini thread 状态：{self.path}") from exc

    def save(self, state: GeminiConversationState) -> None:
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
            raise ThreadStateError(f"无法写入 Gemini thread 状态：{self.path}") from exc


class GeminiRuntimeAdapter:
    """直接调用 Google Gemini REST API 的极简异步适配器。"""

    def __init__(
        self,
        *,
        api_key: str,
        state_store: GeminiStateStore,
        model: str = "gemini-3.7-flash",
        developer_instructions: str | None = None,
        timeout: float = 60.0,
    ) -> None:
        self.api_key = api_key.strip()
        self.state_store = state_store
        self.model = model
        self.developer_instructions = developer_instructions
        self.timeout = timeout
        self._cached_state: GeminiConversationState | None = None

    def _get_or_create_state(self) -> GeminiConversationState:
        if self._cached_state is None:
            self._cached_state = self.state_store.load()
            if self._cached_state is None:
                self._cached_state = GeminiConversationState(thread_id=f"gemini_{uuid.uuid4()}")
                self.state_store.save(self._cached_state)
        return self._cached_state

    async def _post_generate(self, model: str, payload: dict[str, Any]) -> httpx.Response:
        url = f"https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent?key={self.api_key}"
        async with httpx.AsyncClient(timeout=self.timeout) as client:
            return await client.post(url, json=payload)

    async def send(self, message: str) -> RuntimeReply:
        if not message.strip():
            raise ValueError("消息不能为空")
        if not self.api_key:
            raise RuntimeUnavailableError("未配置 Gemini API Key")

        state = self._get_or_create_state()

        # 构建并追加当前用户轮次
        user_turn = {"role": "user", "parts": [{"text": message}]}
        request_contents = [*state.contents, user_turn]

        payload: dict[str, Any] = {"contents": request_contents}
        if self.developer_instructions:
            payload["systemInstruction"] = {
                "parts": [{"text": self.developer_instructions}]
            }

        response: httpx.Response | None = None
        last_error: Exception | None = None
        models_to_try = [self.model]
        if self.model != "gemini-3.6-flash":
            models_to_try.append("gemini-3.6-flash")
        if "gemini-3.5-flash" not in models_to_try:
            models_to_try.append("gemini-3.5-flash")

        for current_model in models_to_try:
            for attempt in range(2):
                try:
                    response = await self._post_generate(current_model, payload)
                    if response.status_code == 200:
                        break
                    elif response.status_code in (500, 502, 503, 504, 429):
                        await asyncio.sleep(0.6 * (attempt + 1))
                        continue
                    else:
                        break
                except httpx.RequestError as exc:
                    last_error = exc
                    await asyncio.sleep(0.6 * (attempt + 1))
                    continue

            if response is not None and response.status_code == 200:
                break

        if response is None:
            raise RuntimeUnavailableError(f"Gemini 网络连接失败：{last_error or '多次尝试无响应'}")

        if response.status_code != 200:
            error_detail = response.text[:300]
            raise RuntimeUnavailableError(f"Gemini 返回异常 HTTP {response.status_code}：{error_detail}")

        try:
            data = response.json()
            candidates = data.get("candidates", [])
            if not candidates:
                raise RuntimeUnavailableError("Gemini 未返回有效 candidate 内容")
            parts = candidates[0].get("content", {}).get("parts", [])
            if not parts:
                raise RuntimeUnavailableError("Gemini candidate content 为空")
            
            # 过滤掉内部 reasoning thought，提取可读 text
            text_parts = [
                part.get("text", "")
                for part in parts
                if not part.get("thought", False) and "text" in part
            ]
            reply_text = "".join(text_parts).strip()
            if not reply_text:
                # 降级：若过滤后为空则拼接全部 text
                reply_text = "".join(part.get("text", "") for part in parts).strip()
        except (ValueError, KeyError) as exc:
            raise RuntimeUnavailableError("解析 Gemini 响应结构失败") from exc

        turn_id = f"turn_{uuid.uuid4()}"

        # 更新并持久化状态
        model_turn = {"role": "model", "parts": [{"text": reply_text}]}
        state.contents.append(user_turn)
        state.contents.append(model_turn)
        self.state_store.save(state)

        return RuntimeReply(
            thread_id=state.thread_id,
            turn_id=turn_id,
            text=reply_text,
        )
