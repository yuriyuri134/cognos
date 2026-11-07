#!/usr/bin/env python3
"""把 server.py 的空间工具开成 HTTP 形式的 MCP，让 ChatGPT（网页版的开发者模式连接器）走进 Cognos。

ChatGPT 是从 OpenAI 的服务器连过来的，连不到本机的 127.0.0.1，所以要配一条公网 HTTPS 隧道
（start-chatgpt-mcp.ps1 起 Cloudflare 临时隧道）。这里只做 MCP 的 Streamable HTTP：
POST 一条（或一批）JSON-RPC，回 application/json；不开 SSE 推送。

谁能进来：地址里带一串口令（/mcp/<口令>），对不上一律 404。ChatGPT 连接器选「不需要认证」，
把带口令的整条地址填进去。口令就是钥匙，别贴到别处；换口令删掉 .secrets/chatgpt-mcp-token 再起一次。

ChatGPT 写下的东西记在 COGNOS_SUBJECT 名下，默认 ChatGPT。只开空间里的通用工具（读、留尝试、写读法、
反对和回应、挂上没完的、停在哪），不开委托运行用的那几个（start_work、hand_back、write_content、adopt……），
那些要桥登记过的运行才有意义。

只用标准库。环境变量（没设就从 deploy/nextcloud-trial/.secrets/ 下读）：
  COGNOS_CHATGPT_TOKEN  地址里的口令（.secrets/chatgpt-mcp-token；没有就生成一串写进去）
  COGNOS_APP_PASSWORD   Nextcloud 应用密码（.secrets/chatgpt-app-password，没有再用 claude-code-app-password）
  COGNOS_URL / COGNOS_USER  同 server.py
  COGNOS_SUBJECT        默认 ChatGPT
  COGNOS_TOOLS          默认见 DEFAULT_TOOLS
  COGNOS_CHATGPT_HOST   默认 127.0.0.1（隧道用 docker 跑时要设 0.0.0.0）
  COGNOS_CHATGPT_PORT   默认 8644
"""
from __future__ import annotations

import hmac
import importlib.util
import json
import os
import secrets
import sys
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

HERE = Path(__file__).resolve().parent
SECRETS = HERE.parent.parent / "deploy" / "nextcloud-trial" / ".secrets"
DEFAULT_TOOLS = ("list_spaces", "enter_space", "search_traces", "leave_trace", "add_reading",
                 "hold_premise", "hit_premise", "answer_hit", "hang_open", "stop_here")
MAX_BODY = 2 * 1024 * 1024


def _secret_file(name: str) -> str:
    path = SECRETS / name
    return path.read_text(encoding="utf-8").strip() if path.is_file() else ""


def load_token() -> str:
    token = os.environ.get("COGNOS_CHATGPT_TOKEN", "").strip() or _secret_file("chatgpt-mcp-token")
    if not token:
        token = secrets.token_urlsafe(32)
        SECRETS.mkdir(parents=True, exist_ok=True)
        (SECRETS / "chatgpt-mcp-token").write_text(token + "\n", encoding="utf-8")
    return token


def load_server():
    os.environ.setdefault("COGNOS_SUBJECT", "ChatGPT")
    os.environ.setdefault("COGNOS_TOOLS", ",".join(DEFAULT_TOOLS))
    if not os.environ.get("COGNOS_APP_PASSWORD"):
        password = _secret_file("chatgpt-app-password") or _secret_file("claude-code-app-password")
        if password:
            os.environ["COGNOS_APP_PASSWORD"] = password
    for fixed in ("COGNOS_BOUND_SPACE_ID", "COGNOS_BOUND_RUN_ID"):
        os.environ.pop(fixed, None)
    spec = importlib.util.spec_from_file_location("cognos_space_server", HERE / "server.py")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def make_handler(server_module, token: str):
    path_ok = "/mcp/" + token

    class Handler(BaseHTTPRequestHandler):
        protocol_version = "HTTP/1.1"

        def log_request(self, code="-", size="-"):  # 不把带口令的路径写进日志
            sys.stderr.write(f"{self.log_date_time_string()} {self.command} {code}\n")

        def _authorized(self) -> bool:
            path = self.path.split("?", 1)[0].rstrip("/")
            return hmac.compare_digest(path.encode(), path_ok.encode())

        def _send(self, status: int, body: bytes = b"", ctype: str = "application/json"):
            self.send_response(status)
            if body:
                self.send_header("Content-Type", ctype + "; charset=utf-8")
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            if body:
                self.wfile.write(body)

        def do_GET(self):
            if self.path == "/health":
                return self._send(200, b'{"ok":true}')
            if not self._authorized():
                return self._send(404)
            # 不开服务器往外推的 SSE 流
            self.send_response(405)
            self.send_header("Allow", "POST")
            self.send_header("Content-Length", "0")
            self.end_headers()

        def do_DELETE(self):
            self._send(204 if self._authorized() else 404)

        def do_POST(self):
            if not self._authorized():
                return self._send(404)
            length = int(self.headers.get("Content-Length") or 0)
            if length <= 0 or length > MAX_BODY:
                return self._send(413 if length > MAX_BODY else 400)
            try:
                message = json.loads(self.rfile.read(length).decode("utf-8"))
            except ValueError:
                body = {"jsonrpc": "2.0", "id": None, "error": {"code": -32700, "message": "Parse error"}}
                return self._send(400, json.dumps(body).encode())
            batch = isinstance(message, list)
            answers = [a for a in (server_module.respond(m) for m in (message if batch else [message])
                                   if isinstance(m, dict)) if a is not None]
            if not answers:
                return self._send(202)
            payload = answers if batch else answers[0]
            self._send(200, json.dumps(payload, ensure_ascii=False).encode("utf-8"))

    return Handler


def main() -> None:
    token = load_token()
    module = load_server()
    if not module.PASSWORD:
        raise SystemExit("没有应用密码：设 COGNOS_APP_PASSWORD，或写到 deploy/nextcloud-trial/.secrets/chatgpt-app-password")
    host = os.environ.get("COGNOS_CHATGPT_HOST", "127.0.0.1")
    port = int(os.environ.get("COGNOS_CHATGPT_PORT", "8644"))
    httpd = ThreadingHTTPServer((host, port), make_handler(module, token))
    print(f"cognos-space（给 ChatGPT）在 http://{host}:{port}/mcp/<口令> 上，主体 {module.SUBJECT}", flush=True)
    httpd.serve_forever()


if __name__ == "__main__":
    main()
