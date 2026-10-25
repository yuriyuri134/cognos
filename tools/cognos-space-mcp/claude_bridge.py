#!/usr/bin/env python3
"""让 Cognos 里被叫进空间的那位换成本机的 Claude Code。

Nextcloud 跑在 Docker 里，Claude Code 在本机。这个小服务在本机听 /chat：
Cognos 把入口（和 Hermes 拿到的是同一份，只是主体换成 Claude Code）、之前的对话和这次的原话发过来，
它起一次 `claude -p`，把同目录的 server.py 作为 MCP 接给它。Claude Code 在空间里留下的尝试和读法
走的是同一套 OCS 接口，记为〔AI Claude Code〕。它的回答原样回给 Cognos。

Claude Code 在这里只开三个工具：leave_trace、add_reading、search_traces。读写文件、跑命令都关着，
因为叫它的是一个网页请求，不是坐在电脑前的人。

只用标准库。环境变量（没设就从 deploy/nextcloud-trial/.secrets/ 下读同名文件）：
  COGNOS_BRIDGE_TOKEN  Cognos 调用时带的口令（.secrets/claude-bridge-token），必填
  COGNOS_APP_PASSWORD  给 MCP 用的 Nextcloud 应用密码（.secrets/claude-code-app-password），必填
  COGNOS_URL           默认 http://127.0.0.1:8780
  COGNOS_USER          默认 cognos-admin
  COGNOS_BRIDGE_HOST   默认 127.0.0.1
  COGNOS_BRIDGE_PORT   默认 8643
  CLAUDE_BRIDGE_MODEL  不设就用 Claude Code 自己的默认模型
"""
from __future__ import annotations

import hmac
import json
import os
import shutil
import subprocess
import sys
import tempfile
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

HERE = Path(__file__).resolve().parent
SECRETS = HERE.parent.parent / "deploy" / "nextcloud-trial" / ".secrets"
SERVER = HERE / "server.py"
TOOLS = ["leave_trace", "add_reading", "search_traces"]


def secret(env: str, filename: str) -> str:
    value = os.environ.get(env, "").strip()
    if value:
        return value
    path = SECRETS / filename
    return path.read_text(encoding="utf-8").strip() if path.is_file() else ""


TOKEN = secret("COGNOS_BRIDGE_TOKEN", "claude-bridge-token")
APP_PASSWORD = secret("COGNOS_APP_PASSWORD", "claude-code-app-password")
COGNOS_URL = os.environ.get("COGNOS_URL", "http://127.0.0.1:8780")
COGNOS_USER = os.environ.get("COGNOS_USER", "cognos-admin")
HOST = os.environ.get("COGNOS_BRIDGE_HOST", "127.0.0.1")
PORT = int(os.environ.get("COGNOS_BRIDGE_PORT", "8643"))
MODEL = os.environ.get("CLAUDE_BRIDGE_MODEL", "")


def prompt_from(history: list[dict], message: str, caller: str) -> str:
    lines = []
    if history:
        lines.append("## 这片空间里之前的对话")
        for item in history:
            who = caller if item.get("role") == "user" else "你"
            lines.append(f"{who}：{item.get('content', '')}")
        lines.append("")
    lines.append(f"## {caller}现在说")
    lines.append(message)
    return "\n".join(lines)


def run_claude(subject: str, system: str, prompt: str) -> str:
    exe = shutil.which("claude.cmd") or shutil.which("claude")
    if exe is None:
        raise RuntimeError("本机 PATH 里找不到 claude")
    with tempfile.TemporaryDirectory(prefix="cognos-claude-") as work:
        workdir = Path(work)
        # claude.cmd（批处理）遇到换行会丢掉后面的参数，所以入口写成文件传进去
        (workdir / "system.txt").write_text(system, encoding="utf-8")
        (workdir / "mcp.json").write_text(json.dumps({"mcpServers": {"cognos-space": {
            "command": sys.executable,
            "args": [str(SERVER)],
            "env": {
                "COGNOS_URL": COGNOS_URL,
                "COGNOS_USER": COGNOS_USER,
                "COGNOS_APP_PASSWORD": APP_PASSWORD,
                "COGNOS_SUBJECT": subject,
                "COGNOS_TOOLS": ",".join(TOOLS),
            },
        }}}, ensure_ascii=False), encoding="utf-8")
        cmd = [exe, "-p", "--system-prompt-file", str(workdir / "system.txt"),
               "--mcp-config", str(workdir / "mcp.json"), "--strict-mcp-config",
               "--tools", "", "--allowedTools", ",".join(f"mcp__cognos-space__{t}" for t in TOOLS),
               "--no-session-persistence", "--setting-sources", "", "--output-format", "json"]
        if MODEL:
            cmd += ["--model", MODEL]
        done = subprocess.run(cmd, input=prompt, capture_output=True, text=True, encoding="utf-8",
                              cwd=workdir, timeout=280)
    try:
        out = json.loads(done.stdout)
    except json.JSONDecodeError:
        raise RuntimeError("claude 没有正常返回：" + (done.stderr or done.stdout)[-300:]) from None
    if out.get("is_error") or not out.get("result"):
        raise RuntimeError("claude 出错：" + str(out.get("result") or out.get("subtype")))
    return out["result"]


class Handler(BaseHTTPRequestHandler):
    def send_json(self, status: int, body: dict) -> None:
        data = json.dumps(body, ensure_ascii=False).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def do_GET(self) -> None:  # noqa: N802
        if self.path == "/health":
            self.send_json(200, {"ok": True, "claude": bool(shutil.which("claude.cmd") or shutil.which("claude"))})
        else:
            self.send_json(404, {"error": "not found"})

    def do_POST(self) -> None:  # noqa: N802
        if self.path != "/chat":
            self.send_json(404, {"error": "not found"})
            return
        if not hmac.compare_digest(self.headers.get("X-Cognos-Bridge-Token", ""), TOKEN):
            self.send_json(401, {"error": "口令不对"})
            return
        try:
            length = int(self.headers.get("Content-Length", "0"))
            body = json.loads(self.rfile.read(length).decode("utf-8"))
            prompt = prompt_from(body.get("history") or [], str(body["message"]), str(body.get("caller") or "交互对象"))
            content = run_claude(str(body.get("subject") or "Claude Code"), str(body["system"]), prompt)
        except subprocess.TimeoutExpired:
            self.send_json(504, {"error": "Claude Code 超时没有回答"})
            return
        except (KeyError, ValueError) as error:
            self.send_json(400, {"error": f"请求不完整：{error}"})
            return
        except RuntimeError as error:
            self.send_json(502, {"error": str(error)})
            return
        self.send_json(200, {"content": content})

    def log_message(self, fmt: str, *args) -> None:
        sys.stderr.write("[claude-bridge] " + (fmt % args) + "\n")


def main() -> None:
    # Windows 控制台默认 GBK，日志重定向到文件时中文会乱
    for stream in (sys.stdout, sys.stderr):
        stream.reconfigure(encoding="utf-8")
    if not TOKEN:
        raise SystemExit("没有口令：设 COGNOS_BRIDGE_TOKEN，或写到 deploy/nextcloud-trial/.secrets/claude-bridge-token")
    if not APP_PASSWORD:
        raise SystemExit("没有应用密码：设 COGNOS_APP_PASSWORD，或写到 deploy/nextcloud-trial/.secrets/claude-code-app-password")
    server = ThreadingHTTPServer((HOST, PORT), Handler)
    print(f"Claude Code 桥在 http://{HOST}:{PORT}（/chat，/health）", flush=True)
    server.serve_forever()


if __name__ == "__main__":
    main()
