"""
probe.llm_driver
================
真实 LLM (Gemini) 模型驱动器。
通过 Tool-Use 循环驱动自主 Agent 进行观察、思考、决策与工具调用。
严禁硬编码步骤、严禁注入答案补丁、严禁人工规定停机点。
"""

from __future__ import annotations

import json
import os
import time
from pathlib import Path
from typing import Any

import httpx

from agent_runner import AgentModelDriver


class GeminiModelDriver:
    """基于 Google Gemini API 的真实自主决策驱动器。"""

    def __init__(
        self,
        api_key: str | None = None,
        primary_model: str = "gemini-3.5-flash-lite",
        fallback_models: list[str] | None = None,
        temperature: float = 0.2,
        timeout_sec: float = 60.0,
        proxy: str | None = None,
    ) -> None:
        self.api_key = api_key or self._load_api_key()
        self.primary_model = primary_model
        self.fallback_models = fallback_models or ["gemini-3.1-flash-lite", "gemini-flash-lite-latest", "gemini-3.5-flash"]
        self.temperature = temperature
        self.timeout_sec = timeout_sec
        self.proxy = proxy or self._detect_proxy()

    @staticmethod
    def _detect_proxy() -> str | None:
        for k in ["HTTPS_PROXY", "HTTP_PROXY", "https_proxy", "http_proxy", "ALL_PROXY", "all_proxy"]:
            val = os.environ.get(k)
            if val:
                return val.strip()
        # 自动检测本地常见代理端口
        for port in [7897, 7890, 10809, 10808]:
            p = f"http://127.0.0.1:{port}"
            try:
                with httpx.Client(proxy=p, timeout=1.5) as client:
                    if client.get("https://www.google.com").status_code == 200:
                        return p
            except Exception:
                pass
        return None

    @staticmethod
    def _load_api_key() -> str:
        # 1. 尝试系统环境变量
        key = os.environ.get("GEMINI_API_KEY") or os.environ.get("GOOGLE_API_KEY")
        if key:
            return key.strip().strip("'\"")

        # 2. 尝试读取 cognos/web/.env
        probe_dir = Path(__file__).resolve().parent
        web_env = probe_dir.parent / "web" / ".env"
        if web_env.exists():
            for line in web_env.read_text(encoding="utf-8").splitlines():
                if line.startswith("GEMINI_API_KEY="):
                    return line.split("=", 1)[1].strip().strip("'\"")
        return ""

    def decide_next_action(
        self,
        system_prompt: str,
        messages: list[dict[str, Any]],
        available_tools: list[dict[str, Any]],
    ) -> tuple[str, dict[str, Any], str]:
        if not self.api_key:
            raise RuntimeError("Gemini API key is not configured. Cannot run real LLM driver.")

        tools_desc = "\n".join(
            f"- {t['name']}({', '.join(f'{k}: {v}' for k, v in t.get('parameters', {}).items())}): {t.get('description', '')}"
            for t in available_tools
        )

        full_system_prompt = (
            f"{system_prompt}\n\n"
            "### 可用工具集 (Available Tools):\n"
            f"{tools_desc}\n\n"
            "### 输出规范 (Strict JSON Output Format):\n"
            "你必须且只能输出如下格式的单个合法 JSON 对象：\n"
            "{\n"
            '  "thought": "你的观察、分析与当前动作意图",\n'
            '  "tool": "调用的工具名称（必须属于上述可用工具集）",\n'
            '  "arguments": { "参数名": "参数值" }\n'
            "}\n"
            "注意：若任务已完成或无法继续，请调用 finish 工具给出最终总结。\n"
        )

        # 构造 Gemini contents 对话历史
        contents: list[dict[str, Any]] = []
        for msg in messages:
            role = msg.get("role", "user")
            content = msg.get("content", "")
            # Gemini REST API 支持 "user" 与 "model" 两种角色
            gemini_role = "model" if role == "assistant" else "user"
            contents.append({
                "role": gemini_role,
                "parts": [{"text": str(content)}]
            })

        models_to_try = [self.primary_model] + [m for m in self.fallback_models if m != self.primary_model]

        last_error = ""
        for model_name in models_to_try:
            url = f"https://generativelanguage.googleapis.com/v1beta/models/{model_name}:generateContent?key={self.api_key}"
            payload = {
                "systemInstruction": {"parts": [{"text": full_system_prompt}]},
                "contents": contents,
                "generationConfig": {
                    "responseMimeType": "application/json",
                    "temperature": self.temperature,
                }
            }

            for attempt in range(2):
                try:
                    with httpx.Client(timeout=self.timeout_sec, proxy=self.proxy) as client:
                        res = client.post(url, json=payload)
                    
                    if res.status_code == 200:
                        data = res.json()
                        candidates = data.get("candidates", [])
                        if not candidates:
                            continue
                        parts = candidates[0].get("content", {}).get("parts", [])
                        
                        # 组合所有 text 片段
                        all_text = "".join(p.get("text", "") for p in parts if "text" in p).strip()
                        if not all_text:
                            # 降级尝试获取任何 text
                            all_text = "".join(str(p) for p in parts).strip()

                        # 清理可能包含的 Markdown 代码块标记 ```json ... ```
                        cleaned_str = all_text
                        if "```json" in cleaned_str:
                            cleaned_str = cleaned_str.split("```json", 1)[1]
                            if "```" in cleaned_str:
                                cleaned_str = cleaned_str.split("```", 1)[0]
                        elif "```" in cleaned_str:
                            cleaned_str = cleaned_str.split("```", 1)[1]
                            if "```" in cleaned_str:
                                cleaned_str = cleaned_str.split("```", 1)[0]
                        cleaned_str = cleaned_str.strip()

                        # 尝试 JSON 解析
                        parsed = None
                        # 策略 1: 直接解析 cleaned_str
                        try:
                            parsed = json.loads(cleaned_str)
                        except Exception:
                            pass

                        # 策略 2: 正则贪婪匹配最外层 {...}
                        if parsed is None or not isinstance(parsed, dict):
                            import re, ast
                            match = re.search(r"(\{[\s\S]*\})", all_text)
                            if match:
                                json_str = match.group(1)
                                try:
                                    parsed = json.loads(json_str)
                                except Exception:
                                    try:
                                        parsed = ast.literal_eval(json_str)
                                    except Exception:
                                        pass

                        # 策略 3: ast.literal_eval cleaned_str
                        if parsed is None or not isinstance(parsed, dict):
                            import ast
                            try:
                                parsed = ast.literal_eval(cleaned_str)
                            except Exception:
                                pass

                        if parsed is None or not isinstance(parsed, dict):
                            raise ValueError(f"No valid JSON/Dict found in model output: {all_text[:200]}")

                        tool_name = parsed.get("tool", "finish")
                        arguments = parsed.get("arguments", {})
                        thought = parsed.get("thought", "")
                        print(f"    [LLM Action] Tool: {tool_name} | Args: {arguments} | Thought: {thought[:80]}...")
                        return tool_name, arguments, thought
                    
                    elif res.status_code in (500, 502, 503, 504, 429):
                        time.sleep(1.0 * (attempt + 1))
                        continue
                    else:
                        last_error = f"HTTP {res.status_code}: {res.text[:300]}"
                        print(f"    [LLM API Error] {model_name} HTTP {res.status_code}: {res.text[:200]}")
                        break
                except Exception as exc:
                    last_error = str(exc)
                    print(f"    [LLM Exception] {model_name}: {exc}")
                    time.sleep(1.0 * (attempt + 1))
                    continue

        # 如果全部重试与模型降级均失败，返回降级 finish 声明
        print(f"    [LLM Failed] Returning fallback finish: {last_error}")
        return "finish", {}, f"Model API invocation failed across all fallbacks: {last_error}"
