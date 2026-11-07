#!/usr/bin/env python3
"""让 Cognos 里被叫进空间的那位换成本机的 Claude Code。

Nextcloud 跑在 Docker 里，Claude Code 在本机。这个小服务在本机听 /chat：
Cognos 把入口（和 Hermes 拿到的是同一份，只是主体换成 Claude Code）、之前的对话和这次的原话发过来，
桥马上回一句「收到」，然后在后台起一次 `claude -p`，把同目录的 server.py 作为 MCP 接给它。
它做多久都行（默认上限 30 分钟），做完把回答经 OCS 接口发回这段对话。
它在空间里留下的尝试和读法走的是同一套 OCS 接口，记为Claude Code 的名字，做的过程中侧栏就能看到。

它能用的：
- 空间里的 leave_trace、add_reading、search_traces；
- 上网：WebSearch、WebFetch；
- 读写文件，只限这片空间自己的工作目录（COGNOS_SPACES_DIR/<类型>-<id>，默认 ~/Documents/cognos-spaces/），
  目录外的读写 Claude Code 会自己拒掉；
- Cognos 自己的那片空间（Cognos 发来 repo=true，见 occ cognos:space:import --as-self）：
  工作目录换成 Cognos 仓库的一份副本（git worktree），在一条新分支 cognos-self/... 上。
  它写下的想法连着这里的代码，被反对了要改成新的，就是真的改代码。做完桥把改动提交到这条分支（不合、不推），
  回答末尾写上分支名，由交互对象来看、来合；没改代码就把分支和副本收掉。
  改了代码，桥在提交以后对改过的文件跑检查（语法、JSON，再加 COGNOS_SELF_CHECK），结果作为现实结果带回 Cognos：
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
  COGNOS_RUN_LOG_DIR   每次运行的完整输入输出存到哪，默认 deploy/nextcloud-trial/evidence/claude-bridge/runs（不进 git）
  COGNOS_RUN_LABEL     给这段时间的运行打个标签（比如 A、B），写进每次的 meta.json，对照时分组用
  COGNOS_BRIDGE_OUTBOX 结果待发送目录，默认 deploy/nextcloud-trial/evidence/claude-bridge/outbox（不进 git）
  COGNOS_BRIDGE_RETRY  发不回去的结果隔多少秒再发一次，默认 30

结果一定回得来：Cognos 叫桥之前先记一次运行，把 run_id 带过来。桥收下时在 outbox/inflight/<run_id>.json 记一笔；
做完先把要发回的结果存进 outbox/pending/<result_id>.json（result_id 由桥生成，带 run_id），再删掉 inflight 那笔，
然后才发回。发成功才删 pending 里那个文件；发不回去就留着，隔一会儿再发，桥重启时也先把它们重发一遍。
Cognos 按 result_id 只收一次，所以重发不会多出一条回答，也不会多叫一次下一件。
桥重启时 inflight 里还有的，是上一次做到一半桥就没了的：报告给 Cognos 标成 unknown（不知道做没做完），不自己重跑。
Cognos 回 4xx 拒收的结果（比如那次运行不在了）挪进 outbox/rejected/，不再发，留着给人看。

每次运行存一个目录：system.txt（入口 + 桥加的话，就是 --system-prompt-file 的全文）、prompt.txt（stdin 的全文）、
stream.jsonl（stream-json 原样，含每次用工具）、stderr.txt、meta.json（空间、主体、标签、身体的提交、用时、退出码）。
mcp.json 里有应用密码，不存。
"""
from __future__ import annotations

import base64
import ast
import hmac
import json
import os
import re
import shutil
import subprocess
import sys
import tempfile
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

HERE = Path(__file__).resolve().parent
SECRETS = HERE.parent.parent / "deploy" / "nextcloud-trial" / ".secrets"
SERVER = HERE / "server.py"
PUBLIC_STATE = SECRETS.parent / ".public-access-state.json"
TOOLS = ["leave_trace", "add_reading", "search_traces", "hold_premise", "hit_premise", "answer_hit", "hang_open", "stop_here", "note_basis", "write_content", "adopt", "list_work_items", "get_work_item", "preview_work_split", "update_work_item", "split_work_item", "list_attempts", "get_attempt",
         "read_space", "write_part", "note_for_you"]
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
CODEX_APP_PASSWORD = secret("COGNOS_CODEX_APP_PASSWORD", "codex-app-password")
CODEX_MODEL = os.environ.get("COGNOS_CODEX_MODEL", "")
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
REPO = Path(os.environ.get("COGNOS_REPO") or HERE.parent.parent)
SELF_BASE = os.environ.get("COGNOS_SELF_BASE", "master")
SELF_CHECK = os.environ.get("COGNOS_SELF_CHECK", "").strip()
PHP_CONTAINER = os.environ.get("COGNOS_PHP_CONTAINER", "cognos-nextcloud-trial-app-1")
SELF_MERGE = os.environ.get("COGNOS_SELF_MERGE", "") == "1"
RUN_LOG_DIR = Path(os.environ.get("COGNOS_RUN_LOG_DIR") or REPO / "deploy" / "nextcloud-trial" / "evidence" / "claude-bridge" / "runs")
RUN_LABEL = os.environ.get("COGNOS_RUN_LABEL", "").strip()
OUTBOX_DIR = Path(os.environ.get("COGNOS_BRIDGE_OUTBOX") or REPO / "deploy" / "nextcloud-trial" / "evidence" / "claude-bridge" / "outbox")
RETRY_SECONDS = int(os.environ.get("COGNOS_BRIDGE_RETRY", "30"))
# 同一时间只让一个线程重发，免得同一个结果从两处同时发出去（Cognos 会去重，但没必要）
OUTBOX_LOCK = threading.Lock()
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
        # 升了版本号或加了迁移：重启不会升级，Nextcloud 会整站 503，要在发回前 occ upgrade
        if any(f.endswith("appinfo/info.xml") or "/lib/Migration/" in f for f in changed):
            restarts.append(upgrade_app())
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


def upgrade_app() -> str:
    """版本号变了或有新迁移：在 app 容器里跑 occ upgrade，整站才不会停在「需要升级」。"""
    docker = shutil.which("docker")
    if docker is None:
        return "app：本机找不到 docker，没升级，要在容器里跑 `occ upgrade`，不然整站 503。"
    done = subprocess.run([docker, "exec", "-u", "www-data", PHP_CONTAINER, "php", "occ", "upgrade"],
                          capture_output=True, text=True, encoding="utf-8", timeout=600)
    if done.returncode:
        return f"app：`occ upgrade` 没成：{(done.stderr or done.stdout).strip()[-200:]}。整站可能 503，要在容器里手动跑。"
    return "app：跑了 `occ upgrade`，版本和迁移生效。"


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
            "- 前提的 governs 写着它管着这里的哪段代码。"
            "改完不用提交：你做完以后桥会把改动提交到这条分支，由交互对象来看、来合。不要切别的分支，不要推。",
        ]
    else:
        lines.append(f"- 这片空间自己的工作目录：{workdir}。只能在这里面读写文件；做出来的东西放这里，下次进来还在。")
    if commands_allowed():
        lines.append("- 可以跑命令（Bash）。只在工作目录里做事，不碰目录外面的东西。")
    else:
        lines.append("- 不能跑命令。要跑的，把脚本和怎么跑写进工作目录，停在这里并写明。")
    lines += [
        f"- 时间：最多 {TIMEOUT // 60} 分钟。最后的回答会发回这段对话，交互对象可能不在线。",
    ]
    return "\n".join(lines)


def prompt_from(history: list[dict], message: str, caller: str, subject: str = "") -> str:
    lines = []
    if history:
        lines.append("## 这片空间里之前的对话")
        for item in history:
            author = str(item.get("author") or "")
            if item.get("role") == "user":
                who = caller
            elif author and author != subject:
                who = author  # 别人回的（比如换着用的 GPT / Claude），不是你
            elif author or subject != "GPT":
                who = "你"
            else:
                who = "AI（以前回的，没记是谁）"
            lines.append(f"{who}：{item.get('content', '')}")
        lines.append("")
    lines.append(f"## {caller}现在说")
    lines.append(message)
    return "\n".join(lines)


def body_commit() -> str:
    """身体（桥和 app 跑的那份仓库）现在在哪个提交上，有没改的就带上 +dirty。"""
    try:
        head = git("rev-parse", "--short", "HEAD", cwd=REPO).strip()
        dirty = git("status", "--porcelain", "--untracked-files=no", cwd=REPO).strip()
        return head + ("+dirty" if dirty else "")
    except Exception as error:  # 记不下也不挡运行
        return f"unknown（{error}）"


_CLAUDE_VERSION: str | None = None


def claude_version() -> str:
    """本机 claude 的版本：--setting-sources 等参数的行为随版本变，对照时要知道是哪一版。"""
    global _CLAUDE_VERSION
    if _CLAUDE_VERSION is None:
        exe = shutil.which("claude.cmd") or shutil.which("claude")
        try:
            _CLAUDE_VERSION = subprocess.run([exe, "--version"], capture_output=True, text=True, timeout=30).stdout.strip() if exe else "not found"
        except Exception as error:
            _CLAUDE_VERSION = f"unknown（{error}）"
    return _CLAUDE_VERSION


def record_run(job: dict, subject: str, system_text: str, prompt: str, started: float,
               stdout: str, stderr: str, returncode, timed_out: bool) -> Path | None:
    """把这一次实际给出去的和拿回来的原样存下，改前改后才有东西可比。存不下只写 stderr，不挡运行。"""
    try:
        stamp = time.strftime("%Y%m%d-%H%M%S", time.localtime(started))
        safe = "".join(c if c.isalnum() or c in "-_" else "_" for c in subject)
        run_dir = RUN_LOG_DIR / f"{stamp}-{job.get('space_type')}-{job.get('space_id')}-{safe}"
        n = 1
        while run_dir.exists():
            n += 1
            run_dir = run_dir.with_name(f"{run_dir.name.split('~')[0]}~{n}")
        run_dir.mkdir(parents=True)
        (run_dir / "system.txt").write_text(system_text, encoding="utf-8")
        (run_dir / "prompt.txt").write_text(prompt, encoding="utf-8")
        (run_dir / "stream.jsonl").write_text(stdout or "", encoding="utf-8")
        (run_dir / "stderr.txt").write_text(stderr or "", encoding="utf-8")
        meta = {
            "label": RUN_LABEL or None,
            "started": time.strftime("%Y-%m-%dT%H:%M:%S%z", time.localtime(started)),
            "seconds": round(time.time() - started, 1),
            "space_type": job.get("space_type"),
            "space_id": job.get("space_id"),
            "subject": subject,
            "caller": job.get("caller"),
            "reply_to": job.get("reply_to"),
            "hit_id": job.get("hit_id"),
            "open_id": job.get("open_id"),
            "run_id": job.get("run_id"),
            "repo": bool(job.get("repo")),
            "body_commit": body_commit(),
            "model": MODEL or None,
            "claude_version": claude_version(),
            "returncode": returncode,
            "timed_out": timed_out,
        }
        (run_dir / "meta.json").write_text(json.dumps(meta, ensure_ascii=False, indent=2), encoding="utf-8")
        return run_dir
    except Exception as error:
        sys.stderr.write(f"[claude-bridge] 这次运行没存下：{error}\n")
        return None


def run_claude(subject: str, system: str, prompt: str, workdir: Path, wt: dict | None = None, job: dict | None = None) -> str:
    exe = shutil.which("claude.cmd") or shutil.which("claude")
    if exe is None:
        raise RuntimeError("本机 PATH 里找不到 claude")
    builtin = WEB_TOOLS + FILE_TOOLS + (["Bash"] if commands_allowed() else [])
    allowed = [f"mcp__cognos-space__{t}" for t in TOOLS] + WEB_TOOLS + (["Bash"] if commands_allowed() else [])
    # 入口和 MCP 配置（含应用密码）放临时目录，不放进它的工作目录
    with tempfile.TemporaryDirectory(prefix="cognos-claude-") as tmp:
        tmpdir = Path(tmp)
        # claude.cmd（批处理）遇到换行会丢掉后面的参数，所以入口写成文件传进去
        system_text = system + "\n" + hands(workdir, wt)
        (tmpdir / "system.txt").write_text(system_text, encoding="utf-8")
        (tmpdir / "mcp.json").write_text(json.dumps({"mcpServers": {"cognos-space": {
            "command": sys.executable,
            "args": [str(SERVER)],
            "env": {
                "COGNOS_URL": COGNOS_URL,
                "COGNOS_USER": COGNOS_USER,
                "COGNOS_APP_PASSWORD": APP_PASSWORD,
                "COGNOS_SUBJECT": subject,
                "COGNOS_RUN_ID": str((job or {}).get("run_id") or ""),
                "COGNOS_BOUND_RUN_ID": "",
                "COGNOS_BOUND_SPACE_ID": str((job or {}).get("space_id") or ""),
                "COGNOS_BOUND_SPACE_TYPE": (job or {}).get("space_type", "creation"),
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
        env = dict(os.environ) if KEEP_API_ENV else {k: v for k, v in os.environ.items() if k not in API_ENV}
        started = time.time()
        try:
            done = subprocess.run(cmd, input=prompt, capture_output=True, text=True, encoding="utf-8",
                                  cwd=workdir, env=env, timeout=TIMEOUT)
        except subprocess.TimeoutExpired as error:
            out = error.stdout.decode("utf-8", "replace") if isinstance(error.stdout, bytes) else (error.stdout or "")
            err = error.stderr.decode("utf-8", "replace") if isinstance(error.stderr, bytes) else (error.stderr or "")
            record_run(job or {}, subject, system_text, prompt, started, out, err, None, True)
            raise
        record_run(job or {}, subject, system_text, prompt, started, done.stdout, done.stderr, done.returncode, False)
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


def codex_configured() -> bool:
    return bool(CODEX_APP_PASSWORD and shutil.which("codex"))


def codex_job(body: dict, job: dict) -> dict:
    if body.get("mode") == "chat":
        # 在一段对话里选了 GPT：普通聊天，每次带全文，只读
        if body.get("executor") != "codex" or body.get("subject") != "GPT" or body.get("space_type") != "creation":
            raise ValueError("GPT 聊天请求不对")
        if not re.fullmatch(r"[1-9][0-9]*", job["space_id"]):
            raise ValueError("对话编号无效")
        if body.get("caller_uid") != COGNOS_USER:
            raise ValueError("聊天账号与本机 Codex 应用凭据账号不一致")
        effort = body.get("reasoning_effort", "default")
        if effort not in ("default", "low", "medium", "high", "xhigh", "max"):
            raise ValueError("无效的思考强度")
        return {**job, "executor": "codex", "mode": "chat", "caller_uid": body["caller_uid"], "reasoning_effort": effort}
    if body.get("executor") != "codex" or body.get("subject") != "Codex" or body.get("space_type") != "creation":
        raise ValueError("Codex 只接收在项目位置登记的 Codex 委托")
    if not re.fullmatch(r"[1-9][0-9]*", job["space_id"]):
        raise ValueError("项目位置会话编号无效")
    for key in ("run_id", "delegation_id"):
        if not isinstance(body.get(key), str) or not re.fullmatch(r"[a-f0-9]{32}", body[key]):
            raise ValueError(f"{key} 无效")
    if body.get("caller_uid") != COGNOS_USER:
        raise ValueError("委托账号与本机 Codex 应用凭据账号不一致")
    if any(body.get(key) for key in ("repo", "open_id", "hit_id", "base_branch")):
        raise ValueError("Codex 委托不能启动仓库副本、反对回应或自动续跑")
    flags = ("may_edit", "may_adopt", "may_manage_work")
    if any(key in body and type(body[key]) is not bool for key in flags):
        raise ValueError("委托权限必须明确为布尔值")
    return {**job, "executor": "codex", "caller_uid": body["caller_uid"], "delegation_id": body["delegation_id"],
            **{key: body.get(key, False) for key in flags}}


def run_codex(job: dict) -> str:
    from codex_entry import delegation_tools, run

    if job.get("mode") == "chat":
        tools = ("enter_space", "read_space", "check_changes", "list_attempts", "get_attempt")
        prompt = ("你在 Cognos 的一段对话里，被交互对象选来回话。下面是这一处的说明，再往下是这段对话的全文和他刚说的话。"
                  "像在对话里一样直接回他；需要时用 Cognos 工具读，但你不能改东西。\n\n" + job["system"] + "\n\n" + job["prompt"])
    else:
        tools = delegation_tools(may_edit=job["may_edit"], may_adopt=job["may_adopt"], may_manage_work=job["may_manage_work"])
        prompt = ("你在 Cognos 中收到一份已登记的委托。只处理当前委托与实际授予的范围。"
                  "需要时用 Cognos 工具读取当前信息，写出最终答复，桥会交回。\n"
                  f"工具绑定的实际运行编号：{job['run_id']}，位置会话编号：{job['space_id']}。\n\n"
                  + job["system"] + "\n\n" + job["prompt"])
    output = RUN_LOG_DIR / ("codex-" + job["run_id"])
    result = run(job["space_id"], prompt, output, subject=job.get("subject") or "Codex", model=CODEX_MODEL or None,
                 timeout=TIMEOUT, run_id=job["run_id"], tools=tools,
                 reasoning_effort=None if job.get("reasoning_effort", "default") == "default" else job["reasoning_effort"],
                 environment={"COGNOS_URL": COGNOS_URL, "COGNOS_USER": job["caller_uid"],
                              "COGNOS_APP_PASSWORD": CODEX_APP_PASSWORD})
    return result["content"]


def cognos_post(path: str, body: dict, *, executor: str = "claude") -> None:
    """POST 到 Cognos 的 OCS 接口（/ocs/v2.php/apps/cognos/api/v1.0/<path>）。失败抛 URLError / HTTPError。"""
    url = COGNOS_URL.rstrip("/") + "/ocs/v2.php/apps/cognos/api/v1.0/" + path
    credential = CODEX_APP_PASSWORD if executor == "codex" else APP_PASSWORD
    if not credential:
        raise urllib.error.URLError("本机应用凭据未配置，结果保留待发送")
    token = base64.b64encode(f"{COGNOS_USER}:{credential}".encode("utf-8")).decode("ascii")
    request = urllib.request.Request(url, method="POST", data=json.dumps(body, ensure_ascii=False).encode("utf-8"), headers={
        "Authorization": "Basic " + token,
        "OCS-APIRequest": "true",
        "Accept": "application/json",
        "Content-Type": "application/json",
    })
    with urllib.request.urlopen(request, timeout=60) as response:
        response.read()


def post_back(result: dict) -> None:
    """把一个结果发回这段对话（OCS：/api/v1.0/spaces/<类型>/<id>/messages）。

    result 里有 run_id、result_id（Cognos 按它只收一次）、subject、content；
    reply_to：回的是对话里哪一句；被反对叫回来的是 0（不回任何一句）。
    """
    if result.get("post_path"):
        # 读者的结果：发到 Cognos 指定的地方（readers/<id>），不进对话
        body = {k: v for k, v in result.items() if k not in ("space_type", "space_id", "post_path")}
        cognos_post(str(result["post_path"]), body)
        return
    path = ("spaces/" + urllib.parse.quote(str(result["space_type"]), safe="") + "/"
            + urllib.parse.quote(str(result["space_id"]), safe="") + "/messages")
    body = {k: v for k, v in result.items() if k not in ("space_type", "space_id", "executor", "caller_uid")}
    if result.get("executor") == "codex":
        if result.get("caller_uid") != COGNOS_USER:
            raise urllib.error.URLError("待发送结果绑定的委托账号与当前桥不一致")
        cognos_post(path, body, executor="codex")
    else:
        cognos_post(path, body)


def outbox(sub: str) -> Path:
    path = OUTBOX_DIR / sub
    path.mkdir(parents=True, exist_ok=True)
    return path


def write_json(path: Path, data: dict) -> None:
    """先写临时文件再换名：写到一半桥没了，也不会留下半个结果。"""
    tmp = path.with_name(path.name + ".tmp")
    tmp.write_text(json.dumps(data, ensure_ascii=False, indent=1), encoding="utf-8")
    os.replace(tmp, path)


def mark_inflight(job: dict) -> None:
    """桥收下了这一件：记一笔。做完、结果存进 pending 以后才删；桥重启时还在的，就是做到一半没了的。"""
    write_json(outbox("inflight") / f"{job['run_id']}.json", {
        "run_id": job["run_id"], "space_type": job["space_type"], "space_id": job["space_id"],
        "subject": job["subject"], "accepted_at": time.strftime("%Y-%m-%dT%H:%M:%S%z"), "pid": os.getpid(),
        **({"executor": "codex", "caller_uid": job["caller_uid"]} if job.get("executor") == "codex" else {}),
    })


def pid_alive(pid: int) -> bool:
    """那个桥进程还在不在（长进身体换桥时，旧桥还在把手上的做完）。Windows 上 os.kill 会直接结束进程，不能拿来探。"""
    if pid <= 0:
        return False
    if os.name == "nt":
        import ctypes
        handle = ctypes.windll.kernel32.OpenProcess(0x1000, False, pid)  # PROCESS_QUERY_LIMITED_INFORMATION
        if not handle:
            return False
        code = ctypes.c_ulong()
        ok = ctypes.windll.kernel32.GetExitCodeProcess(handle, ctypes.byref(code))
        ctypes.windll.kernel32.CloseHandle(handle)
        return bool(ok) and code.value == 259  # STILL_ACTIVE
    try:
        os.kill(pid, 0)
    except ProcessLookupError:
        return False
    except PermissionError:
        return True
    return True


def stale_inflight() -> set[str]:
    """inflight 里记着、但记它的桥进程已经没了的：上一次做到一半桥就没了。"""
    stale = set()
    for path in outbox("inflight").glob("*.json"):
        try:
            record = json.loads(path.read_text(encoding="utf-8"))
        except (OSError, ValueError):
            continue
        if int(record.get("pid") or 0) != os.getpid() and not pid_alive(int(record.get("pid") or 0)):
            stale.add(path.stem)
    return stale


def clear_inflight(run_id: str) -> None:
    (outbox("inflight") / f"{run_id}.json").unlink(missing_ok=True)


def save_result(result: dict) -> Path:
    path = outbox("pending") / f"{result['result_id']}.json"
    write_json(path, result)
    return path


def deliver(path: Path) -> bool:
    """发一个存着的结果。发成功删掉文件；Cognos 拒收（4xx，除了超时和限流）挪进 rejected；其余留着下次再发。"""
    with OUTBOX_LOCK:
        if not path.is_file():
            return True  # 别处已经发掉了
        result = json.loads(path.read_text(encoding="utf-8"))
        try:
            post_back(result)
        except urllib.error.HTTPError as error:
            if 400 <= error.code < 500 and error.code not in (408, 429):
                detail = error.read().decode("utf-8", "replace")[:500]
                sys.stderr.write(f"[claude-bridge] Cognos 拒收结果 {path.name}（HTTP {error.code}）：{detail}\n")
                os.replace(path, outbox("rejected") / path.name)
                return True
            sys.stderr.write(f"[claude-bridge] 结果 {path.name} 发不回 Cognos（HTTP {error.code}），留着再发\n")
            return False
        except (urllib.error.URLError, OSError) as error:
            sys.stderr.write(f"[claude-bridge] 结果 {path.name} 发不回 Cognos：{error}，留着再发\n")
            return False
        path.unlink(missing_ok=True)
        return True


def resend_pending() -> int:
    """把 pending 里还没发出去的按先后发一遍，返回还剩几个。"""
    left = 0
    for path in sorted(outbox("pending").glob("*.json"), key=lambda p: p.stat().st_mtime):
        if not deliver(path):
            left += 1
    return left


def report_unknown(run_ids: set[str]) -> None:
    """上一次桥没了时做到一半的：告诉 Cognos 标成 unknown。报告成了（或 Cognos 那边没有这次运行）就删掉那笔。"""
    for run_id in sorted(run_ids):
        path = outbox("inflight") / f"{run_id}.json"
        try:
            record = json.loads(path.read_text(encoding="utf-8")) if path.is_file() else {}
            body = {"reason": "桥重启时发现这一件做到一半桥就没了：不知道做没做完，没有重跑。"}
            if record.get("executor") == "codex":
                if record.get("caller_uid") != COGNOS_USER:
                    raise urllib.error.URLError("运行绑定的委托账号与当前桥不一致")
                cognos_post(f"runs/{run_id}/unknown", body, executor="codex")
            else:
                cognos_post(f"runs/{run_id}/unknown", body)
        except urllib.error.HTTPError as error:
            if error.code != 404:
                sys.stderr.write(f"[claude-bridge] 报告运行 {run_id} 做到一半没了，Cognos 回 HTTP {error.code}，过会儿再报\n")
                continue
        except (urllib.error.URLError, OSError) as error:
            sys.stderr.write(f"[claude-bridge] 报告运行 {run_id} 做到一半没了，没报上：{error}，过会儿再报\n")
            continue
        path.unlink(missing_ok=True)
        run_ids.discard(run_id)


def outbox_loop(stale: set[str]) -> None:
    """桥起来后：先报告上一次做到一半没了的、重发没发出去的结果；之后隔一会儿再发一遍还剩的。"""
    while True:
        try:
            if stale:
                report_unknown(stale)
            resend_pending()
        except Exception as error:  # 这条线不能死
            sys.stderr.write(f"[claude-bridge] 重发结果时出错：{error}\n")
        time.sleep(RETRY_SECONDS)


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
            if job.get("executor") == "codex":
                content = run_codex(job)
            else:
                if job.get("repo"):
                    wt = open_worktree(space_type, space_id, job.get("hit_id"), job.get("base_branch"), job.get("open_id"))
                workdir = wt["path"] if wt else workdir_for(space_type, space_id)
                content = run_claude(subject, job["system"], job["prompt"], workdir, wt, job)
        except subprocess.TimeoutExpired:
            content = f"（{subject} 做了 {TIMEOUT // 60} 分钟还没做完，被停下了。做到哪里，看侧栏里它留下的发生和工作目录。）"
            back["timed_out"] = True
        except Exception as error:  # 出错也要让交互对象在对话里看到
            if job.get("executor") == "codex":
                sys.stderr.write(f"[claude-bridge] Codex 运行 {job['run_id']} 未完成（{type(error).__name__}），请核对本机运行日志。\n")
                content = f"（{subject} 这次没做完。本机运行记录在 runs/codex-{job['run_id']}。）"
            else:
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
        result = {
            "result_id": uuid.uuid4().hex,
            "run_id": job["run_id"],
            "space_type": space_type,
            "space_id": space_id,
            "subject": subject,
            "content": content,
            "reply_to": job.get("reply_to"),
            "hit_id": job.get("hit_id"),
            **({"executor": "codex", "caller_uid": job["caller_uid"]} if job.get("executor") == "codex" else {}),
            **back,
        }
        # 先存下来再发：发不回去（Cognos 停着、网断了、桥这时没了）结果还在 pending 里，之后会重发
        pending = save_result(result)
        clear_inflight(job["run_id"])
        # 先放开这片空间：回答发回去时，排着的下一条反对会接着叫进来
        with BUSY_LOCK:
            BUSY.discard(key)
        released = True
        if new_bridge:
            # 先换桥再发回：发回时 Cognos 会接着叫下一次，要叫到新的那个。新桥起来也会重发 pending 里的，Cognos 只收一次
            result["content"] = content + "\n- " + restart_bridge()
            with OUTBOX_LOCK:
                if pending.is_file():
                    write_json(pending, result)
        deliver(pending)
    except Exception as error:
        sys.stderr.write(f"[claude-bridge] 这一件的结果没存下：{error}\n")
    finally:
        # 已经放开过的不再放：发回时同一片空间可能已经叫进了新的一件，删的会是它的标记
        if not released:
            with BUSY_LOCK:
                BUSY.discard(key)
        if EXIT.is_set():
            POSTED.set()


def run_plain(subject: str, system: str, prompt: str, job: dict) -> str:
    """读者用：一次没有任何工具的 claude -p。不接 MCP，不读本机设置，工作目录是空的临时目录，只拿到给它的这段文字。"""
    exe = shutil.which("claude.cmd") or shutil.which("claude")
    if exe is None:
        raise RuntimeError("本机 PATH 里找不到 claude")
    with tempfile.TemporaryDirectory(prefix="cognos-reader-") as tmp:
        tmpdir = Path(tmp)
        (tmpdir / "system.txt").write_text(system, encoding="utf-8")
        work_dir = tmpdir / "work"
        work_dir.mkdir()
        cmd = [exe, "-p", "--system-prompt-file", str(tmpdir / "system.txt"),
               "--strict-mcp-config", "--tools", "",
               "--no-session-persistence", "--setting-sources", "",
               "--output-format", "stream-json", "--verbose"]
        if MODEL:
            cmd += ["--model", MODEL]
        env = dict(os.environ) if KEEP_API_ENV else {k: v for k, v in os.environ.items() if k not in API_ENV}
        started = time.time()
        try:
            done = subprocess.run(cmd, input=prompt, capture_output=True, text=True, encoding="utf-8",
                                  cwd=work_dir, env=env, timeout=TIMEOUT)
        except subprocess.TimeoutExpired as error:
            out = error.stdout.decode("utf-8", "replace") if isinstance(error.stdout, bytes) else (error.stdout or "")
            err = error.stderr.decode("utf-8", "replace") if isinstance(error.stderr, bytes) else (error.stderr or "")
            record_run(job, subject, system, prompt, started, out, err, None, True)
            raise
        record_run(job, subject, system, prompt, started, done.stdout, done.stderr, done.returncode, False)
    return answer_from(done.stdout, done.stderr)


def reading_ok(text: str) -> bool:
    """读者回来的是不是那个 JSON，并且说了「是什么」。不是的话不能当成一份读法（试读时有一位只回了「息。」）。"""
    start, end = text.find("{"), text.rfind("}")
    if start < 0 or end <= start:
        return False
    try:
        data = json.loads(text[start:end + 1])
    except ValueError:
        return False
    return isinstance(data, dict) and bool(str(data.get("what") or "").strip())


def read_work(job: dict) -> None:
    """三个读者同时各读各的，读完由比较的那位看三份读法（不看原文），结果发回 Cognos 的 post_path。"""
    key = job["busy_key"]
    try:
        answers: list[str | None] = [None] * len(job["readers"])
        errors: list[str] = []

        def one(i: int, reader: dict) -> None:
            try:
                answers[i] = run_plain(f"读者{i + 1}-{reader['label']}", reader["system"], reader["prompt"], job)
            except Exception as error:  # 一个读者没读完，不挡另外两个
                errors.append(f"读者{i + 1}（{reader['label']}）没读完：{error}")

        threads = [threading.Thread(target=one, args=(i, r), daemon=True) for i, r in enumerate(job["readers"])]
        for t in threads:
            t.start()
        for t in threads:
            t.join()
        got = [(i, a) for i, a in enumerate(answers) if a]
        compare = ""
        if got and not any(reading_ok(a) for _, a in got):
            errors.append("读者都没读出东西")  # 不拿几句不是读法的话去比，省一次额度
        elif got:
            parts = [f"## 读者{i + 1}（{job['readers'][i]['label']}）\n"
                     + (a if reading_ok(a) else f"（这位没读出东西，只回了：{a.strip()[:80]}）") for i, a in got]
            try:
                compare = run_plain("读者-比较", job["compare"]["system"], "\n\n".join(parts), job)
            except Exception as error:
                errors.append(f"比较没做完：{error}")
        result = {
            "result_id": uuid.uuid4().hex,
            "run_id": job["run_id"],
            "space_type": job["space_type"],
            "space_id": job["space_id"],
            "post_path": job["post_path"],
            "subject": job["subject"],
            "readings": [a or "" for a in answers],
            "compare": compare,
            "error": "；".join(errors),
        }
        pending = save_result(result)
        clear_inflight(job["run_id"])
        deliver(pending)
    except Exception as error:
        sys.stderr.write(f"[claude-bridge] 读者这一次的结果没存下：{error}\n")
    finally:
        with BUSY_LOCK:
            BUSY.discard(key)


def read_job(body: dict) -> dict:
    run_id = str(body["run_id"])
    if not run_id.isalnum() or len(run_id) != 32:
        raise ValueError("run_id 不对")
    post_path = str(body["post_path"])
    if not re.fullmatch(r"readers/[0-9]+", post_path):
        raise ValueError("post_path 不对")
    readers = body.get("readers")
    if not isinstance(readers, list) or not 1 <= len(readers) <= 5:
        raise ValueError("readers 要是 1 到 5 个")
    clean = []
    for r in readers:
        if not isinstance(r, dict) or not isinstance(r.get("system"), str) or not isinstance(r.get("prompt"), str):
            raise ValueError("每个读者要有 system 和 prompt")
        clean.append({"label": str(r.get("label") or ""), "system": r["system"], "prompt": r["prompt"]})
    compare = body.get("compare")
    if not isinstance(compare, dict) or not isinstance(compare.get("system"), str):
        raise ValueError("compare 要有 system")
    return {
        "run_id": run_id, "post_path": post_path, "readers": clean, "compare": {"system": compare["system"]},
        "subject": str(body.get("subject") or "读者"),
        "space_type": str(body.get("space_type") or "creation"), "space_id": str(body.get("space_id") or ""),
        "caller": "读者", "busy_key": "read/" + post_path,
    }


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
                                 "commands": commands_allowed(), "busy": sorted(BUSY),
                                 "codex_supported": True, "codex_configured": codex_configured(),
                                 "pending": len(list(outbox("pending").glob("*.json")))})
        else:
            self.send_json(404, {"error": "not found"})

    def do_POST(self) -> None:  # noqa: N802
        if self.path not in ("/chat", "/codex", "/read"):
            self.send_json(404, {"error": "not found"})
            return
        if not hmac.compare_digest(self.headers.get("X-Cognos-Bridge-Token", ""), TOKEN):
            self.send_json(401, {"error": "口令不对"})
            return
        if self.path == "/read":
            self.do_read()
            return
        try:
            length = int(self.headers.get("Content-Length", "0"))
            body = json.loads(self.rfile.read(length).decode("utf-8"))
            if not isinstance(body, dict):
                raise ValueError("请求必须是 JSON 对象")
            history = body.get("history") or []
            if not isinstance(history, list) or any(not isinstance(item, dict) for item in history):
                raise ValueError("对话历史必须是消息列表")
            job = {
                "space_type": str(body.get("space_type") or "creation"),
                "space_id": str(body["space_id"]),
                "subject": str(body.get("subject") or "Claude Code"),
                "system": str(body["system"]),
                "prompt": prompt_from(history, str(body["message"]), str(body.get("caller") or "交互对象"), str(body.get("subject") or "Claude Code")),
                "caller": str(body.get("caller") or "交互对象"),
                "repo": bool(body.get("repo")),
                "hit_id": body.get("hit_id"),
                "reply_to": body.get("reply_to"),
                "open_id": body.get("open_id"),
                "run_started": body.get("run_started"),
                "base_branch": body.get("base_branch"),
            }
            # Cognos 叫之前先记了这次运行；没有 run_id，做完的结果它不会收
            job["run_id"] = str(body["run_id"])
            if not job["run_id"].isalnum() or len(job["run_id"]) != 32:
                raise ValueError("run_id 不对")
            if self.path == "/codex":
                job = codex_job(body, job)
            elif body.get("executor") == "codex":
                raise ValueError("Codex 委托须使用专用 /codex 入口")
        except (KeyError, TypeError, ValueError) as error:
            self.send_json(400, {"error": f"请求不完整：{error}"})
            return
        if self.path == "/codex" and not codex_configured():
            self.send_json(422, {"error": "本机 Codex CLI 或 Cognos 应用凭据尚未配置"})
            return
        key = f"{job['space_type']}/{job['space_id']}"
        with BUSY_LOCK:
            if key in BUSY:
                self.send_json(409, {"error": f"{job['subject']} 还在这片空间里做上一件事，做完会发回对话"})
                return
            BUSY.add(key)
        try:
            mark_inflight(job)
        except OSError as error:
            with BUSY_LOCK:
                BUSY.discard(key)
            self.send_json(500, {"error": f"桥记不下这一件（{error}），没有接"})
            return
        threading.Thread(target=work, args=(job,), daemon=True).start()
        self.send_json(202, {"accepted": True, "subject": job["subject"]})

    def do_read(self) -> None:
        try:
            length = int(self.headers.get("Content-Length", "0"))
            body = json.loads(self.rfile.read(length).decode("utf-8"))
            if not isinstance(body, dict):
                raise ValueError("请求必须是 JSON 对象")
            job = read_job(body)
        except (KeyError, TypeError, ValueError) as error:
            self.send_json(400, {"error": f"请求不完整：{error}"})
            return
        with BUSY_LOCK:
            if job["busy_key"] in BUSY:
                self.send_json(409, {"error": "读者还在读这一次"})
                return
            BUSY.add(job["busy_key"])
        try:
            mark_inflight(job)
        except OSError as error:
            with BUSY_LOCK:
                BUSY.discard(job["busy_key"])
            self.send_json(500, {"error": f"桥记不下这一件（{error}），没有接"})
            return
        threading.Thread(target=read_work, args=(job,), daemon=True).start()
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
    # 开始接新的之前先看：上一次做到一半没了的、没发出去的结果
    stale = stale_inflight()
    threading.Thread(target=outbox_loop, args=(stale,), daemon=True).start()
    print(f"Claude Code 桥在 http://{HOST}:{PORT}（/chat，/codex，/read，/health）；工作目录 {SPACES_DIR}；"
          f"命令{'开' if commands_allowed() else '关'}；长进身体{'开' if SELF_MERGE else '关'}；"
          f"待发送 {len(list(outbox('pending').glob('*.json')))} 个，做到一半没了的 {len(stale)} 个", flush=True)
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
