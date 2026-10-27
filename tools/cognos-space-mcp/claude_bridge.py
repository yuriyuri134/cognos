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
- Cognos 自己的那片空间（Cognos 发来 repo=true，见 occ cognos:space:import --as-self）：
  工作目录换成 Cognos 仓库的一份副本（git worktree），在一条新分支 cognos-self/... 上。
  它押着的前提连着这里的代码，被顶了要重新切，就是真的改代码。做完桥把改动提交到这条分支（不合、不推），
  回答末尾写上分支名，由交互对象来看、来合；没改代码就把分支和副本收掉。
  改了代码，桥在提交以后对改过的文件跑检查（语法、JSON，再加 COGNOS_SELF_CHECK），结果作为〔世〕带回 Cognos：
  回答它的不只是它自己说的话。没过，Cognos 会接着叫它（见 ContinuationService）。
- 从一件没完的叫起来的（Cognos 发来 open_id）：做完把 open_id、开始时间、分支、检查一起发回，
  Cognos 看它停在哪，决定要不要从口子接着叫下一次；下一次在 Cognos 自己的空间里从上一次的分支接着改（base_branch）。
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
  COGNOS_REPO          Cognos 仓库在哪，默认这个文件往上两层
  COGNOS_SELF_BASE     Cognos 自己的空间从哪条分支切新分支，默认 master（没有就用 HEAD）
  COGNOS_SELF_CHECK    Cognos 自己的空间改完以后，在仓库副本里再跑的一条检查命令（可不设），非 0 算没过
  COGNOS_PHP_CONTAINER Nextcloud 的 app 容器：本机没有 php 时用它检查 PHP 语法，长进身体后重启它，默认 cognos-nextcloud-trial-app-1
  COGNOS_SELF_MERGE    设成 1：Cognos 自己的空间里改的代码检查过了，就合进本机正在跑的分支（身体），
                       改了 cognos-app 就重启 app 容器，改了桥就重启桥。合之前打本机标签 cognos-body/...，可以退回；不推。
"""
from __future__ import annotations

import base64
import ast
import hmac
import json
import os
import shutil
import subprocess
import sys
import tempfile
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

HERE = Path(__file__).resolve().parent
SECRETS = HERE.parent.parent / "deploy" / "nextcloud-trial" / ".secrets"
SERVER = HERE / "server.py"
PUBLIC_STATE = SECRETS.parent / ".public-access-state.json"
TOOLS = ["leave_trace", "add_reading", "search_traces", "hold_premise", "hit_premise", "answer_hit", "hang_open", "stop_here"]
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
REPO = Path(os.environ.get("COGNOS_REPO") or HERE.parent.parent)
SELF_BASE = os.environ.get("COGNOS_SELF_BASE", "master")
SELF_CHECK = os.environ.get("COGNOS_SELF_CHECK", "").strip()
PHP_CONTAINER = os.environ.get("COGNOS_PHP_CONTAINER", "cognos-nextcloud-trial-app-1")
SELF_MERGE = os.environ.get("COGNOS_SELF_MERGE", "") == "1"
APP_DIR = "deploy/nextcloud-trial/cognos-app/"
BRIDGE_DIR = "tools/cognos-space-mcp/"
HTTPD: ThreadingHTTPServer | None = None
# 桥长了新代码要换掉自己：EXIT 表示正在换，POSTED 表示手上那件的回答已经发回去，主线程这时才退出
EXIT = threading.Event()
POSTED = threading.Event()

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


def git(*args: str, cwd: Path | None = None) -> str:
    done = subprocess.run(["git", *args], cwd=cwd or REPO, capture_output=True, text=True, encoding="utf-8")
    if done.returncode != 0:
        raise RuntimeError(f"git {args[0]}：" + (done.stderr or done.stdout).strip()[-300:])
    return done.stdout.strip()


def open_worktree(space_type: str, space_id: str, hit_id, base_branch: str | None = None, open_id=None) -> dict:
    """Cognos 自己的空间：从 SELF_BASE（接着走时从上一次的分支 base_branch）切一条新分支，放一份仓库副本给它做。"""
    base = "HEAD"
    for candidate in ([base_branch] if base_branch else []) + [SELF_BASE]:
        try:
            git("rev-parse", "--verify", "--quiet", candidate)
            base = candidate
            break
        except RuntimeError:
            continue
    if base == "HEAD":
        # 写成跑着的那条分支（或提交号）：检查要拿它和副本比，在副本里 HEAD 指的是副本自己
        name = git("rev-parse", "--abbrev-ref", "HEAD")
        base = name if name != "HEAD" else git("rev-parse", "HEAD")
    label = f"open-{int(open_id)}" if open_id else (f"hit-{int(hit_id)}" if hit_id else "call")
    branch = "cognos-self/" + label + time.strftime("-%Y%m%d-%H%M%S")
    stem, n = branch, 1
    while True:  # 同一秒里接着叫起来也不撞名
        try:
            git("rev-parse", "--verify", "--quiet", "refs/heads/" + branch)
        except RuntimeError:
            break
        n += 1
        branch = f"{stem}-{n}"
    path = workdir_for(space_type, space_id) / "worktrees" / branch.replace("/", "-")
    path.parent.mkdir(parents=True, exist_ok=True)
    git("worktree", "add", "-q", "-b", branch, str(path), base)
    return {"path": path, "branch": branch, "base": base}


def close_worktree(wt: dict, subject: str, summary: str) -> tuple[str, str | None, dict | None]:
    """有改动就提交到那条分支（不合、不推），对改过的文件跑检查；没改就把分支和副本收掉。

    返回（接在回答后面的一句，下一次从哪条分支接着，检查结果）。
    """
    path, branch, base = wt["path"], wt["branch"], wt["base"]
    if git("status", "--porcelain", cwd=path):
        git("add", "-A", cwd=path)
        message = f"{subject} 在 Cognos 自己的空间里改的\n\n{summary[:1500]}"
        git("-c", "user.name=Cognos", "-c", "user.email=cognos@localhost", "commit", "-q", "-m", message, cwd=path)
    # 它自己提交了、自己合了别的分支，工作区是干净的，也算改了：看分支比起点多了提交没有
    if int(git("rev-list", "--count", f"{base}..HEAD", cwd=path) or "0") > 0:
        commit = git("rev-parse", "--short", "HEAD", cwd=path)
        try:
            checks = check_changes(path, base)
        except Exception as error:  # 检查本身坏了也要说出来，不能当成过了
            checks = {"ok": False, "text": f"检查没跑起来：{error}"}
        git("worktree", "remove", "--force", str(path))
        text = (f"\n\n---\n改动提交在本机分支 `{branch}`（{commit}），从 `{base}` 切出来，没有推。"
                f"\n\n检查（{'过了' if checks['ok'] else '没过'}）：\n{checks['text']}")
        if SELF_MERGE and checks["ok"]:
            grown = grow(branch)
            text += "\n\n" + grown["text"]
            checks["grown"] = grown
        else:
            text += f"\n\n没有合进跑着的那份，由你来看、来合：`git diff {base}...{branch}`。"
        return text, branch, checks
    git("worktree", "remove", "--force", str(path))
    git("branch", "-D", branch)
    # 没改：下一次还从这一次的起点接着
    keep = base if base.startswith("cognos-self/") else None
    return "\n\n---\n这次没有改代码。", keep, None


def grow(branch: str) -> dict:
    """检查过了的改动长进身体：合进本机正在跑的分支（REPO 当前检出的那条）。合不了就照实说，不硬合。"""
    body = git("rev-parse", "--abbrev-ref", "HEAD")
    if body == "HEAD":
        return {"merged": False, "text": "没合：跑着的那份不在任何分支上（detached HEAD）。"}
    if git("status", "--porcelain", "--untracked-files=no"):
        return {"merged": False, "text": f"没合：跑着的那份（`{body}`）有没提交的改动，不动它。"}
    tag = "cognos-body/" + time.strftime("%Y%m%d-%H%M%S")
    git("tag", tag, "HEAD")
    try:
        git("merge", "--ff-only", "-q", branch)
    except RuntimeError:
        try:
            git("-c", "user.name=Cognos", "-c", "user.email=cognos@localhost", "merge", "--no-edit", "-q", branch)
        except RuntimeError as error:
            try:
                git("merge", "--abort")
            except RuntimeError:
                pass
            return {"merged": False, "text": f"没合：和跑着的那份（`{body}`）合不上（{error}），留在分支上等你。"}
    changed = [f for f in git("diff", "--name-only", tag, "HEAD").splitlines() if f.strip()]
    app = any(f.startswith(APP_DIR) for f in changed)
    bridge = any(f.startswith(BRIDGE_DIR) for f in changed)
    restarts = []
    if app:
        restarts.append(restart_app())
    if bridge:
        restarts.append("桥：回答发回去前换成新代码。")
    return {
        "merged": True, "bridge": bridge,
        "text": (f"长进身体了：合进本机跑着的 `{body}`。要退回：`git reset --hard {tag}`（在 `{body}` 上）。"
                 + ("".join("\n- " + r for r in restarts) if restarts else "")),
    }


def restart_app() -> str:
    """改了 cognos-app：重启 app 容器（同版本号时路由缓存在 APCu 里，app:disable/enable 清不掉），等它能应答。"""
    docker = shutil.which("docker")
    if docker is None:
        return "app：本机找不到 docker，没重启，改动要重启 app 容器才生效。"
    done = subprocess.run([docker, "restart", PHP_CONTAINER], capture_output=True, text=True, encoding="utf-8")
    if done.returncode:
        return f"app：重启 {PHP_CONTAINER} 没成：{(done.stderr or done.stdout).strip()[-200:]}"
    for _ in range(60):
        try:
            with urllib.request.urlopen(COGNOS_URL.rstrip("/") + "/status.php", timeout=5) as response:
                if response.status == 200:
                    return f"app：重启了 {PHP_CONTAINER}，新代码生效。"
        except (urllib.error.URLError, OSError):
            pass
        time.sleep(2)
    return f"app：重启了 {PHP_CONTAINER}，两分钟里还没应答。"


def restart_bridge() -> str:
    """桥长了新代码：放开端口，起一个新的桥（读的是身体里的新文件），等它能应答。旧的在回答发回去以后退出。"""
    EXIT.set()
    if HTTPD is not None:
        HTTPD.shutdown()
        HTTPD.server_close()
    logs = REPO / "deploy" / "nextcloud-trial" / "evidence" / "claude-bridge"
    logs.mkdir(parents=True, exist_ok=True)
    stamp = time.strftime("%Y%m%d-%H%M%S")
    flags = 0x00000008 | 0x00000200 if os.name == "nt" else 0  # DETACHED_PROCESS | CREATE_NEW_PROCESS_GROUP
    with open(logs / f"bridge-{stamp}.out.log", "ab") as out, open(logs / f"bridge-{stamp}.err.log", "ab") as err:
        process = subprocess.Popen([sys.executable, "-u", str(HERE / "claude_bridge.py")], cwd=str(REPO),
                                   stdout=out, stderr=err, creationflags=flags, start_new_session=os.name != "nt")
    for _ in range(30):
        try:
            with urllib.request.urlopen(f"http://127.0.0.1:{PORT}/health", timeout=3) as response:
                if json.loads(response.read().decode("utf-8")).get("ok"):
                    return f"桥换成了新代码（pid {process.pid}）。"
        except (urllib.error.URLError, OSError, ValueError):
            pass
        time.sleep(1)
    return f"新桥（pid {process.pid}）30 秒里没应答，看 {logs} 里 bridge-{stamp}.*。"


def check_changes(path: Path, base: str) -> dict:
    """对这次改过的文件跑检查：语法（Python、JS、PHP）、JSON，再加 COGNOS_SELF_CHECK。查不了的照实写出来。"""
    files = [f for f in git("diff", "--name-only", base, "HEAD", cwd=path).splitlines() if f.strip()]
    lines: list[str] = []
    ok = True
    php_local = shutil.which("php")
    docker = shutil.which("docker")
    for name in files:
        file = path / name
        if not file.is_file():
            continue  # 删掉的文件
        suffix = file.suffix.lower()
        error = None
        checked = True
        if suffix == ".py":
            try:
                ast.parse(file.read_text(encoding="utf-8"), filename=name)
            except SyntaxError as e:
                error = f"第 {e.lineno} 行：{e.msg}"
        elif suffix == ".json":
            try:
                json.loads(file.read_text(encoding="utf-8"))
            except ValueError as e:
                error = str(e)
        elif suffix in (".js", ".cjs", ".mjs"):
            node = shutil.which("node")
            if node:
                done = subprocess.run([node, "--check", str(file)], capture_output=True, text=True, encoding="utf-8")
                error = (done.stderr or done.stdout).strip()[-400:] if done.returncode else None
            else:
                checked = False
        elif suffix == ".php":
            if php_local:
                done = subprocess.run([php_local, "-l", str(file)], capture_output=True, text=True, encoding="utf-8")
            elif docker:
                done = subprocess.run([docker, "exec", "-i", PHP_CONTAINER, "php", "-l"], input=file.read_text(encoding="utf-8"),
                                      capture_output=True, text=True, encoding="utf-8")
            else:
                done = None
            if done is None:
                checked = False
            elif done.returncode:
                error = (done.stdout + done.stderr).strip()[-400:]
                if "No such container" in error or "Cannot connect" in error or "error during connect" in error:
                    checked, error = False, None
        else:
            continue  # 文档、样式：没有能跑的检查，不列
        if not checked:
            lines.append(f"- 没检查：{name}（本机没有能检查它的工具）")
        elif error:
            ok = False
            lines.append(f"- ✗ {name}：{error}")
        else:
            lines.append(f"- ✓ {name}")
    if SELF_CHECK:
        done = subprocess.run(SELF_CHECK, shell=True, cwd=path, capture_output=True, text=True, encoding="utf-8", timeout=600)
        tail = (done.stdout + done.stderr).strip()[-800:]
        if done.returncode:
            ok = False
            lines.append(f"- ✗ `{SELF_CHECK}` 退出码 {done.returncode}：{tail}")
        else:
            lines.append(f"- ✓ `{SELF_CHECK}`")
    if not lines:
        lines.append("- 改的都是没有能跑的检查的文件（文档、样式）。")
    return {"ok": ok, "text": "\n".join(lines)}


def hands(workdir: Path, wt: dict | None = None) -> str:
    """告诉它这次手里有什么，接在入口后面。"""
    lines = [
        "",
        "## 这次你手里还有",
        "- 上网：WebSearch、WebFetch。",
    ]
    if wt is not None:
        lines += [
            f"- 工作目录：{workdir}。这是 Cognos 仓库的一份副本，在新分支 {wt['branch']} 上（从 {wt['base']} 切出来）。"
            "产品代码在 deploy/nextcloud-trial/cognos-app/，项目全貌在 PROJECT-OVERVIEW.md。",
            "- 你押着的前提，governs 写着它管着这里的哪段代码。被顶了要重新切，就去改那段代码；只改说法不算。"
            "改完不用提交：你做完以后桥会把改动提交到这条分支，由交互对象来看、来合。不要切别的分支，不要推。",
        ]
    else:
        lines.append(f"- 这片空间自己的工作目录：{workdir}。只能在这里面读写文件；做出来的东西放这里，下次进来还在。")
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


def run_claude(subject: str, system: str, prompt: str, workdir: Path, wt: dict | None = None) -> str:
    exe = shutil.which("claude.cmd") or shutil.which("claude")
    if exe is None:
        raise RuntimeError("本机 PATH 里找不到 claude")
    builtin = WEB_TOOLS + FILE_TOOLS + (["Bash"] if commands_allowed() else [])
    allowed = [f"mcp__cognos-space__{t}" for t in TOOLS] + WEB_TOOLS + (["Bash"] if commands_allowed() else [])
    # 入口和 MCP 配置（含应用密码）放临时目录，不放进它的工作目录
    with tempfile.TemporaryDirectory(prefix="cognos-claude-") as tmp:
        tmpdir = Path(tmp)
        # claude.cmd（批处理）遇到换行会丢掉后面的参数，所以入口写成文件传进去
        (tmpdir / "system.txt").write_text(system + "\n" + hands(workdir, wt), encoding="utf-8")
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
               "--no-session-persistence", "--setting-sources", "",
               "--output-format", "stream-json", "--verbose"]
        if MODEL:
            cmd += ["--model", MODEL]
        done = subprocess.run(cmd, input=prompt, capture_output=True, text=True, encoding="utf-8",
                              cwd=workdir, timeout=TIMEOUT)
    return answer_from(done.stdout, done.stderr)


def answer_from(stdout: str, stderr: str = "") -> str:
    """从 stream-json 里取回答：最后一次用工具之后它说的全部话。

    只取 result 会丢话：它答完以后又补一句（比如后台任务结束时），result 只剩那一句。
    """
    tail: list[str] = []
    result = None
    for line in stdout.splitlines():
        try:
            event = json.loads(line)
        except ValueError:
            continue
        if event.get("type") == "assistant":
            for block in (event.get("message") or {}).get("content") or []:
                if block.get("type") == "tool_use":
                    tail = []
                elif block.get("type") == "text" and block.get("text", "").strip():
                    tail.append(block["text"].strip())
        elif event.get("type") == "result":
            result = event
    if result is None:
        raise RuntimeError("claude 没有正常返回：" + (stderr or stdout)[-300:])
    if result.get("is_error"):
        raise RuntimeError("claude 出错：" + str(result.get("result") or result.get("subtype")))
    answer = "\n\n".join(tail) or (result.get("result") or "")
    if not answer:
        raise RuntimeError("claude 出错：" + str(result.get("subtype")))
    return answer


def post_back(space_type: str, space_id: str, subject: str, content: str, reply_to=None, hit_id=None, extra: dict | None = None) -> None:
    """把回答发回这段对话（OCS：/api/v1.0/spaces/<类型>/<id>/messages）。

    reply_to：回的是对话里哪一句；被顶叫回来的是 0（不回任何一句）。
    """
    url = (COGNOS_URL.rstrip("/") + "/ocs/v2.php/apps/cognos/api/v1.0/spaces/"
           + urllib.parse.quote(space_type, safe="") + "/" + urllib.parse.quote(space_id, safe="") + "/messages")
    token = base64.b64encode(f"{COGNOS_USER}:{APP_PASSWORD}".encode("utf-8")).decode("ascii")
    request = urllib.request.Request(url, method="POST", data=json.dumps(
        {"content": content, "subject": subject, "reply_to": reply_to, "hit_id": hit_id, **(extra or {})}, ensure_ascii=False).encode("utf-8"), headers={
        "Authorization": "Basic " + token,
        "OCS-APIRequest": "true",
        "Accept": "application/json",
        "Content-Type": "application/json",
    })
    with urllib.request.urlopen(request, timeout=60) as response:
        response.read()


def work(job: dict) -> None:
    space_type, space_id, subject = job["space_type"], job["space_id"], job["subject"]
    key = f"{space_type}/{space_id}"
    wt = None
    released = False
    # 从一件没完的叫起来的：这些要带回去，Cognos 才知道从哪接着
    back: dict = {}
    if job.get("open_id"):
        back = {"open_id": job["open_id"], "run_started": job.get("run_started"), "branch": job.get("base_branch")}
    try:
        try:
            if job.get("repo"):
                wt = open_worktree(space_type, space_id, job.get("hit_id"), job.get("base_branch"), job.get("open_id"))
            workdir = wt["path"] if wt else workdir_for(space_type, space_id)
            content = run_claude(subject, job["system"], job["prompt"], workdir, wt)
        except subprocess.TimeoutExpired:
            content = f"（{subject} 做了 {TIMEOUT // 60} 分钟还没做完，被停下了。做到哪里，看侧栏里它留下的发生和工作目录。）"
            back["timed_out"] = True
        except Exception as error:  # 出错也要让交互对象在对话里看到
            content = f"（{subject} 这次没做完：{error}）"
        new_bridge = False
        if wt is not None:
            try:
                tail, branch, checks = close_worktree(wt, subject, content)
                content += tail
                new_bridge = bool(checks and checks.get("grown", {}).get("bridge"))
                if checks is not None:
                    checks = {"ok": checks["ok"], "text": checks["text"]}
                if back:
                    back["branch"] = branch
                    back["checks"] = checks
            except Exception as error:
                content += f"\n\n---\n仓库副本 {wt['path']}（分支 {wt['branch']}）没收好：{error}。改动还在那里。"
        # 先放开这片空间：回答发回去时，排着的下一下顶会接着叫进来
        with BUSY_LOCK:
            BUSY.discard(key)
        released = True
        if new_bridge:
            # 先换桥再发回：发回时 Cognos 会接着叫下一次，要叫到新的那个
            content += "\n- " + restart_bridge()
        post_back(space_type, space_id, subject, content, job.get("reply_to"), job.get("hit_id"), back)
    except (urllib.error.URLError, OSError) as error:
        sys.stderr.write(f"[claude-bridge] 回答发不回 Cognos：{error}\n")
    finally:
        # 已经放开过的不再放：发回时同一片空间可能已经叫进了新的一件，删的会是它的标记
        if not released:
            with BUSY_LOCK:
                BUSY.discard(key)
        if EXIT.is_set():
            POSTED.set()


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
                "repo": bool(body.get("repo")),
                "hit_id": body.get("hit_id"),
                "reply_to": body.get("reply_to"),
                "open_id": body.get("open_id"),
                "run_started": body.get("run_started"),
                "base_branch": body.get("base_branch"),
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
    global HTTPD
    HTTPD = ThreadingHTTPServer((HOST, PORT), Handler)
    print(f"Claude Code 桥在 http://{HOST}:{PORT}（/chat，/health）；工作目录 {SPACES_DIR}；"
          f"命令{'开' if commands_allowed() else '关'}；长进身体{'开' if SELF_MERGE else '关'}", flush=True)
    HTTPD.serve_forever()
    # 换成新桥了：等手上那件把回答发回去再退出
    if EXIT.is_set():
        POSTED.wait(600)
        # 别的空间里还在做的，做完发回去再走
        deadline = time.time() + TIMEOUT + 120
        while BUSY and time.time() < deadline:
            time.sleep(5)


if __name__ == "__main__":
    main()
