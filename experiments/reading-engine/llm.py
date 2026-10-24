"""Minimal OpenAI-compatible chat client for the a6api relay (stdlib only)."""
from __future__ import annotations

import http.client
import json
import os
import re
import time
import urllib.error
import urllib.request
from pathlib import Path

BASE = os.environ.get("READING_LLM_BASE", "https://api.a6api.com/v1")
MODEL = os.environ.get("READING_LLM_MODEL", "claude-sonnet-5-5")


def _key() -> str:
    if os.environ.get("READING_LLM_KEY"):
        return os.environ["READING_LLM_KEY"]
    env = Path(os.environ.get("HERMES_ENV", "//wsl.localhost/Ubuntu-24.04/home/lnp/.hermes/.env"))
    for line in env.read_text(encoding="utf-8").splitlines():
        if line.startswith("HERMES_A6API_KEY="):
            return line.split("=", 1)[1].strip().strip('"')
    raise SystemExit("no LLM key: set READING_LLM_KEY or HERMES_ENV")


KEY = None


def chat(system: str, user: str, *, model: str = MODEL, max_tokens: int = 4000, retries: int = 4) -> str:
    global KEY
    KEY = KEY or _key()
    body = json.dumps({
        "model": model,
        "messages": [{"role": "system", "content": system}, {"role": "user", "content": user}],
        "max_tokens": max_tokens,  # relay returns 502 if temperature is sent to claude-sonnet-5-5
    }).encode()
    for attempt in range(retries):
        req = urllib.request.Request(f"{BASE}/chat/completions", data=body, headers={
            "Authorization": f"Bearer {KEY}", "Content-Type": "application/json",
            "User-Agent": "cognos-reading-engine/0.1"})
        try:
            with urllib.request.urlopen(req, timeout=240) as r:
                return json.loads(r.read())["choices"][0]["message"]["content"]
        except (urllib.error.URLError, OSError, http.client.HTTPException, KeyError, json.JSONDecodeError):
            if attempt == retries - 1:
                raise
            time.sleep(3 * (attempt + 1))
    raise RuntimeError("unreachable")


def chat_json(system: str, user: str, attempts: int = 3, **kw):
    """Ask for JSON; tolerate ```json fences and leading prose; re-ask on truncated/invalid JSON."""
    for attempt in range(attempts):
        text = chat(system + "\n\n只输出一个 JSON 值，不要任何其他文字。理由字段保持简短。", user, **kw)
        m = re.search(r"```(?:json)?\s*(.*?)```", text, re.S)
        if m:
            text = m.group(1)
        start = min([i for i in (text.find("{"), text.find("[")) if i >= 0], default=0)
        try:
            return json.loads(text[start:])
        except json.JSONDecodeError:
            if attempt == attempts - 1:
                raise
