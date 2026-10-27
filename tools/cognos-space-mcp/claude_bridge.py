#!/usr/bin/env python3
"""让 Cognos 里被叫进空间的那位换成本机的 Claude Code。

Nextcloud 跑在 Docker 里，Claude Code 在本机。这个小服务在本机听 /chat：
Cognos 把入口（和 Hermes 拿到的是同一份，只是主体换成 Claude Code）、之前的对话和这次的原话发过来，
桥马上回一句「收到」，然后在后台起一次 `claude -p`，把同目录的 server.py 作为 MCP 接给它。
它做多久都行（默认上限 30 分钟），做完把回答经 OCS 接口发回这段对话。
它在空间里留下的尝试和读法走的是同一套 OCS 接口，记为〔AI Claude Code〕，做的过程中侧栏就能看到。

它能用的：
- 空间里的 leave_trace、add_reading、search_traces；
- 上网：WebSearch、WebFetch；
- 读写文件，只限这片空间自己的工作目录（COGNOS_SPACES_DIR/<类型>-<id>，默认 ~/Documents/cognos-spaces/），
  目录外的读写 Claude Code 会自己拒掉；
- 跑命令只在 COGNOS_BRIDGE_COMMANDS=1 时打开。叫它的是一个网页请求，不是坐在电脑前的人：
  8780 经 start-public.ps1 开到公网时（有 .public-access-state.json），桥不开命令。
  命令不受工作目录限制，Windows 上没有沙箱，打开前要想清楚。

只用标准库。环境变量（没设就从 deploy/nextcloud-trial/.secrets/ 下读同名文件）：
  COGNOS_BRIDGE_TOKEN  Cognos 调用时带的口令（.secrets/claude-bridge-token），必填
  COGNOS_APP_PASSWORD  给 MCP 用的 Nextcloud 应用密码（.secrets/claude-code-app-password），必填
  COGNOS_URL           默认 http://127.0.0.1:8780
  COGNOS_USER          默认 cognos-admin
  COGNOS_BRIDGE_HOST   默认 127.0.0.1
  COGNOS_BRIDGE_PORT   默认 8643
  CLAUDE_BRIDGE_MODEL  不设就用 Claude Code 自己的默认模型
  COGNOS_SPACES_DIR    各片空间的工作目录放在哪，默认 ~/Documents/cognos-spaces
  COGNOS_BRIDGE_COMMANDS  设成 1 才让它跑命令
  COGNOS_BRIDGE_TIMEOUT   一次最多做多少秒，默认 1800
"""
from __future__ import annotations

import base64
import hmac
import json
import os
import shutil
import subprocess
import sys
import tempfile
import threading
import urllib.error
import urllib.parse
import urllib.request
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

HERE = Path(__file__).resolve().parent
SECRETS = HERE.parent.parent / "deploy" / "nextcloud-trial" / ".secrets"
SERVER = HERE / "server.py"
PUBLIC_STATE = SECRETS.parent / ".public-access-state.json"
TOOLS = ["leave_trace", "add_reading", "search_traces"]
WEB_TOOLS = ["WebSearch", "WebFetch"]
FILE_TOOLS = ["Read", "Write", "Edit", "Glob", "Grep"]


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
SPACES_DIR = Path(os.environ.get("COGNOS_SPACES_DIR") or Path.home() / "Documents" / "cognos-spaces")
COMMANDS = os.environ.get("COGNOS_BRIDGE_COMMANDS", "") == "1"
TIMEOUT = int(os.environ.get("COGNOS_BRIDGE_TIMEOUT", "1800"))
# 10/27：PowerShell 配置文件里设的中转站 key 会被继承，claude 就不走交互对象自己的订阅登录了。
# 默认起 claude 前把这几个去掉；真想走中转站，设 COGNOS_BRIDGE_KEEP_API_ENV=1
API_ENV = ("ANTHROPIC_API_KEY", "ANTHROPIC_AUTH_TOKEN", "ANTHROPIC_BASE_URL")
KEEP_API_ENV = os.environ.get("COGNOS_BRIDGE_KEEP_API_ENV", "") == "1"

# 同一片空间同时只做一件事，免得两次叫进来的互相踩着工作目录
BUSY: set[str] = set()
BUSY_LOCK = threading.Lock()


def commands_allowed() -> bool:
    return COMMANDS and not PUBLIC_STATE.exists()


def workdir_for(space_type: str, space_id: str) -> Path:
    safe = "".join(ch for ch in f"{space_type}-{space_id}" if ch.isalnum() or ch in "-_")
    path = SPACES_DIR / safe
    path.mkdir(parents=True, exist_ok=True)
    return path


def hands(workdir: Path) -> str:
    """告诉它这次手里有什么，接在入口后面。"""
    lines = [
        "",
        "## 这次你手里还有",
        "- 上网：WebSearch、WebFetch。",
        f"- 这片空间自己的工作目录：{workdir}。只能在这里面读写文件；做出来的东西放这里，下次进来还在。",
    ]
    if commands_allowed():
        lines.append("- 可以跑命令（Bash）。只在工作目录里做事，不碰目录外面的东西。")
    else:
        lines.append("- 不能跑命令。要跑的，把脚本和怎么跑写进工作目录，停在这里并写明。")
    lines += [
        "- 时间：你回完话不用马上走。能自己做的先做到够不着为止（要交互对象的账号、钱、或另一个人），"
        f"最多 {TIMEOUT // 60} 分钟。最后的回答会发回这段对话，交互对象可能不在线，回答里写清做了什么、东西在哪、停在哪。",
    ]
    return "\n".join(lines)


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


def run_claude(subject: str, system: str, prompt: str, workdir: Path) -> str:
    exe = shutil.which("claude.cmd") or shutil.which("claude")
    if exe is None:
        raise RuntimeError("本机 PATH 里找不到 claude")
    builtin = WEB_TOOLS + FILE_TOOLS + (["Bash"] if commands_allowed() else [])
    allowed = [f"mcp__cognos-space__{t}" for t in TOOLS] + WEB_TOOLS + (["Bash"] if commands_allowed() else [])
    # 入口和 MCP 配置（含应用密码）放临时目录，不放进它的工作目录
    with tempfile.TemporaryDirectory(prefix="cognos-claude-") as tmp:
        tmpdir = Path(tmp)
        # claude.cmd（批处理）遇到换行会丢掉后面的参数，所以入口写成文件传进去
        (tmpdir / "system.txt").write_text(system + "\n" + hands(workdir), encoding="utf-8")
        (tmpdir / "mcp.json").write_text(json.dumps({"mcpServers": {"cognos-space": {
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
        # acceptEdits：工作目录里的读写不用问；目录外的、没列进 allowedTools 的，-p 下一律被拒
        cmd = [exe, "-p", "--system-prompt-file", str(tmpdir / "system.txt"),
               "--mcp-config", str(tmpdir / "mcp.json"), "--strict-mcp-config",
               "--tools", ",".join(builtin), "--allowedTools", ",".join(allowed),
               "--permission-mode", "acceptEdits",
               "--no-session-persistence", "--setting-sources", "", "--output-format", "json"]
        if MODEL:
            cmd += ["--model", MODEL]
        env = dict(os.environ) if KEEP_API_ENV else {k: v for k, v in os.environ.items() if k not in API_ENV}
        done = subprocess.run(cmd, input=prompt, capture_output=True, text=True, encoding="utf-8",
                              cwd=workdir, env=env, timeout=TIMEOUT)
    try:
        out = json.loads(done.stdout)
    except json.JSONDecodeError:
        raise RuntimeError("claude 没有正常返回：" + (done.stderr or done.stdout)[-300:]) from None
    if out.get("is_error") or not out.get("result"):
        raise RuntimeError("claude 出错：" + str(out.get("result") or out.get("subtype")))
    return out["result"]


def post_back(space_type: str, space_id: str, subject: str, content: str) -> None:
    """把回答发回这段对话（OCS：/api/v1.0/spaces/<类型>/<id>/messages）。"""
    url = (COGNOS_URL.rstrip("/") + "/ocs/v2.php/apps/cognos/api/v1.0/spaces/"
           + urllib.parse.quote(space_type, safe="") + "/" + urllib.parse.quote(space_id, safe="") + "/messages")
    token = base64.b64encode(f"{COGNOS_USER}:{APP_PASSWORD}".encode("utf-8")).decode("ascii")
    request = urllib.request.Request(url, method="POST", data=json.dumps(
        {"content": content, "subject": subject}, ensure_ascii=False).encode("utf-8"), headers={
        "Authorization": "Basic " + token,
        "OCS-APIRequest": "true",
        "Accept": "application/json",
        "Content-Type": "application/json",
    })
    with urllib.request.urlopen(request, timeout=60) as response:
        response.read()


def work(job: dict) -> None:
    space_type, space_id, subject = job["space_type"], job["space_id"], job["subject"]
    try:
        try:
            content = run_claude(subject, job["system"], job["prompt"], workdir_for(space_type, space_id))
        except subprocess.TimeoutExpired:
            content = f"（{subject} 做了 {TIMEOUT // 60} 分钟还没做完，被停下了。做到哪里，看侧栏里它留下的发生和工作目录。）"
        except Exception as error:  # 出错也要让交互对象在对话里看到
            content = f"（{subject} 这次没做完：{error}）"
        post_back(space_type, space_id, subject, content)
    except (urllib.error.URLError, OSError) as error:
        sys.stderr.write(f"[claude-bridge] 回答发不回 Cognos：{error}\n")
    finally:
        with BUSY_LOCK:
            BUSY.discard(f"{space_type}/{space_id}")


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
            self.send_json(200, {"ok": True, "claude": bool(shutil.which("claude.cmd") or shutil.which("claude")),
                                 "commands": commands_allowed(), "busy": sorted(BUSY)})
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
            job = {
                "space_type": str(body.get("space_type") or "creation"),
                "space_id": str(body["space_id"]),
                "subject": str(body.get("subject") or "Claude Code"),
                "system": str(body["system"]),
                "prompt": prompt_from(body.get("history") or [], str(body["message"]), str(body.get("caller") or "交互对象")),
            }
        except (KeyError, ValueError) as error:
            self.send_json(400, {"error": f"请求不完整：{error}"})
            return
        key = f"{job['space_type']}/{job['space_id']}"
        with BUSY_LOCK:
            if key in BUSY:
                self.send_json(409, {"error": f"{job['subject']} 还在这片空间里做上一件事，做完会发回对话"})
                return
            BUSY.add(key)
        threading.Thread(target=work, args=(job,), daemon=True).start()
        self.send_json(202, {"accepted": True, "subject": job["subject"]})

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
    print(f"Claude Code 桥在 http://{HOST}:{PORT}（/chat，/health）；工作目录 {SPACES_DIR}；"
          f"命令{'开' if commands_allowed() else '关'}", flush=True)
    server.serve_forever()


if __name__ == "__main__":
    main()
