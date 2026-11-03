"""V12 for real on 8780: two participants in one project at the same time, a new decision in the middle, and one of them
changes the content the other one depends on.

- Participant 1: the local Claude Code behind the bridge (claude_bridge.py on 8643), given a hand-off that may edit the draft.
- Participant 2: a second, separate Claude Code process started here with only the Cognos MCP tools (tools/cognos-space-mcp/server.py)
  and its own name. It never goes through the bridge, so it does not compete for 8643. It walks the agent-neutral path in
  docs/项目管理/Cognos项目设计/03-系统/接入一条路.md: enter_space -> start_work -> note_basis -> (later) hand_back.
- A web session that cannot be woken: the page must say there is no evidence for it.

Uses the trial's cognos-admin and the Claude Code app password from .secrets (read here, never printed). Report in evidence/v12.json.
"""
from __future__ import annotations

import base64
import json
import os
import re
import shutil
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.request
import uuid
from pathlib import Path

ROOT = Path(__file__).resolve().parent
REPO = ROOT.parent.parent
APP = "http://127.0.0.1:8780/index.php/apps/cognos"
SECRETS = ROOT / ".secrets"
ADMIN = ("cognos-admin", (SECRETS / "admin-password").read_text(encoding="utf-8").strip())
APP_PASSWORD = (SECRETS / "claude-code-app-password").read_text(encoding="utf-8").strip()
SECOND = "Claude Code（第二位）"
EVIDENCE = ROOT / "evidence" / "v12.json"
report: dict = {"at": time.strftime("%Y-%m-%dT%H:%M:%S"), "checks": []}


def req(route: str, method: str = "GET", data: dict | None = None, expected: int = 200) -> dict:
    token = base64.b64encode(f"{ADMIN[0]}:{ADMIN[1]}".encode()).decode()
    r = urllib.request.Request(APP + route, method=method, data=None if data is None else json.dumps(data).encode(),
                               headers={"Authorization": "Basic " + token, "OCS-APIRequest": "true", "Accept": "application/json",
                                        "Content-Type": "application/json"})
    try:
        with urllib.request.urlopen(r, timeout=60) as resp:
            status, body = resp.status, json.loads(resp.read().decode())
    except urllib.error.HTTPError as e:
        status, body = e.code, json.loads(e.read().decode() or "{}")
    assert status == expected, f"{method} {route}: {status} {body.get('message')}"
    return body


def rid() -> str:
    return str(uuid.uuid4())


def second(prompt: str) -> str:
    """Run the second participant once: a fresh claude -p with only the Cognos MCP tools."""
    exe = shutil.which("claude.cmd") or shutil.which("claude")
    tools = ["enter_space", "start_work", "note_basis", "check_changes", "hand_back"]
    with tempfile.TemporaryDirectory(prefix="cognos-second-") as tmp:
        cfg = Path(tmp) / "mcp.json"
        cfg.write_text(json.dumps({"mcpServers": {"cognos-space": {
            "command": sys.executable, "args": [str(REPO / "tools" / "cognos-space-mcp" / "server.py")],
            "env": {"COGNOS_URL": "http://127.0.0.1:8780", "COGNOS_USER": ADMIN[0], "COGNOS_APP_PASSWORD": APP_PASSWORD,
                    "COGNOS_SUBJECT": SECOND, "COGNOS_TOOLS": ",".join(tools)}}}}, ensure_ascii=False), encoding="utf-8")
        cmd = [exe, "-p", "--mcp-config", str(cfg), "--strict-mcp-config",
               "--allowedTools", ",".join(f"mcp__cognos-space__{t}" for t in tools),
               "--no-session-persistence", "--setting-sources", "", "--output-format", "text"]
        env = {k: v for k, v in os.environ.items() if k not in ("ANTHROPIC_API_KEY", "ANTHROPIC_AUTH_TOKEN", "ANTHROPIC_BASE_URL")}
        done = subprocess.run(cmd, input=prompt, capture_output=True, text=True, encoding="utf-8", cwd=tmp, env=env, timeout=900)
    if done.returncode != 0:
        raise RuntimeError("second participant failed: " + (done.stderr or done.stdout)[-800:])
    return done.stdout.strip()


def overview(pid: str, loc: str | None = None) -> dict:
    return req(f"/projects/{pid}/overview" + (f"?location_id={loc}" if loc else ""))


def main() -> None:
    stamp = time.strftime("%Y%m%d-%H%M%S")
    p = req("/projects/data", "POST", {"title": f"验证：V12 两位同时 {stamp}", "request_id": rid()})
    x = req(f"/projects/{p['project_id']}/locations", "POST", {"parent_id": p["location_id"], "title": "入口方案", "request_id": rid()})["location_id"]
    req(f"/projects/locations/{x}/content", "PUT", {"expected_revision": 1, "source_message_ids": [], "request_id": rid(), "content":
        "两种入口方案\n\n方案甲：进入一个位置时，先给适用的在生效的决定，再给这一处的当前内容。\n\n方案乙：先给这一处的当前内容，决定只在和要做的事有关时才列出。"})
    d1 = req(f"/projects/{p['project_id']}/decisions", "POST", {"content": "入口里不放固定的开头和结尾。", "scope": "project", "request_id": rid()})["decision_id"]
    loc = req(f"/projects/locations/{x}/data")["location"]
    space = str(loc["creation_id"])
    report.update(project_id=p["project_id"], location_id=x, space_id=space)

    # 第二位，第一段：进来、登记、记依据、写好评审，先不交
    out1 = second(
        f"你是 Cognos 项目里的一位参与者，这次在空间 {space}（space_type 是 creation）。\n"
        "1. 用 enter_space 进来，看入口。\n"
        "2. 用 start_work 登记你在动这一处，purpose 写「评审当前稿的两种入口方案」。\n"
        "3. 你的评审依据的是入口里这一处当前内容的那一版：用 note_basis 记下那个版本号。\n"
        "4. 写出你的评审（三四句）。这一次先不要交回。\n"
        "最后单独一行写 RUN_ID=<start_work 给你的 run_id>，再单独一行写 REVIEW=<你的评审，一行>。")
    run2 = re.search(r"RUN_ID=([a-f0-9]{32})", out1)
    review = re.search(r"REVIEW=(.+)", out1)
    assert run2 and review, "second participant did not report its run: " + out1[-600:]
    run2, review = run2.group(1), review.group(1).strip()
    report["second_phase1"] = out1[-1200:]
    o = overview(p["project_id"])
    mine = [r for r in o["activity"]["runs"] if r["run_id"] == run2]
    assert mine and mine[0]["subject"] == SECOND and mine[0]["state"] == "running", o["activity"]["runs"]
    d1_reach = {r["subject"]: r for r in next(d for d in o["decisions"] if d["id"] == d1)["progress"]}
    assert d1_reach[SECOND]["included_at"], "decision 1 was in the second participant's input"
    report["checks"].append("second participant (separate Claude Code, MCP only) entered, registered its run and noted its basis; it shows under who-is-doing-what and decision 1 shows 进了输入 for it")

    # 中途：小林登记一条新决定
    d2 = req(f"/projects/{p['project_id']}/decisions", "POST", {"content": "评审要指出每种方案最可能失败的地方。", "scope": "project", "request_id": rid()})["decision_id"]

    # 第一位：经桥交出去，允许改当前稿（它会改掉第二位依据的那一版）
    dg = req(f"/projects/locations/{x}/delegations", "POST", {"ask": "在当前稿里，每个方案下面补一句它最可能失败的地方，其他不动", "may_edit": True,
                                                              "return_minutes": 30, "request_id": rid()})
    assert dg["state"] == "sent", dg
    o = overview(p["project_id"])
    subjects = sorted({r["subject"] for r in o["activity"]["runs"]})
    assert subjects == sorted(["Claude Code", SECOND]), f"both at work at once: {subjects}"
    report["simultaneous"] = [{"subject": r["subject"], "state": r["state"], "purpose": r["purpose"]} for r in o["activity"]["runs"]]
    report["checks"].append("both participants show under who-is-doing-what at the same time")
    deadline = time.time() + 600
    while True:
        d = next(d for d in overview(p["project_id"], x)["delegations"] if d["id"] == dg["delegation_id"])
        if d["state"] == "returned" or d["run_state"] in ("failed", "unknown") or time.time() > deadline:
            break
        time.sleep(5)
    assert d["state"] == "returned" and d["applied_revision"] == 3, d
    report["checks"].append("participant 1 came back and wrote revision 3 of the draft (the version participant 2 relied on was 2)")

    # 第二位，第二段：交回；交回前它应当看到新决定和依据被改
    out2 = second(
        f"你在 Cognos 的空间 {space}（space_type 是 creation）里有一次还没交回的运行，run_id 是 {run2}。\n"
        f"你之前写好的评审是：{review}\n"
        "用 hand_back 交回它。如果 hand_back 告诉你起步以后这里变了，先看清变了什么，按需要改你的评审，再带 acknowledged=true 交回。\n"
        "最后用一两句话说说你交回前看到了什么变化、你改了什么。")
    report["second_phase2"] = out2[-1500:]
    o = overview(p["project_id"], x)
    back = next((r for r in o["activity"]["recent"] if r["run_id"] == run2), None)
    assert back, "participant 2's result came back"
    assert any(m["id"] == d2 for m in back["decisions_missing"]), "decision 2 was not in its input: marked"
    assert any(b["location_id"] == x and b["version"] == 2 and b["current"] == 3 for b in back["basis_changed"]), back
    report["drift_shown"] = [{k: b[k] for k in ("title", "version", "current", "added", "removed") if k in b} for b in back["basis_changed"]]
    assert back["drift_seen_at"], "it saw the differences before handing back"
    reach = {d["id"]: {r["subject"]: r for r in d["progress"]} for d in o["decisions"]}
    assert reach[d2]["Claude Code"]["included_at"], "decision 2 was in participant 1's input (it started after)"
    assert not reach[d2][SECOND]["included_at"] and reach[d2][SECOND].get("seen_before_return_at"), "participant 2: not in input, seen before return"
    assert "在生效的决定" in o["handoff_text"] and "评审要指出每种方案最可能失败的地方" in o["handoff_text"]
    report["reach"] = {d: {s: {k: v for k, v in r.items() if k != "subject"} for s, r in by.items()} for d, by in reach.items()}
    report["checks"].append("participant 2 handed back after seeing the drift: its result is kept, marked 'decision 2 not in input' and 'basis revision 2 -> 3', with the time it saw them; decision 2 shows 进了输入 for participant 1 and 交回前看到了 for participant 2; the copyable entry for unreachable web sessions has both decisions")
    report["result"] = "pass"


if __name__ == "__main__":
    code = 0
    try:
        main()
    except Exception as error:  # noqa: BLE001
        report["result"] = "fail"
        report["error"] = str(error)[:2000]
        code = 1
    EVIDENCE.parent.mkdir(parents=True, exist_ok=True)
    EVIDENCE.write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding="utf-8")
    print(json.dumps(report, ensure_ascii=False, indent=2))
    sys.exit(code)
