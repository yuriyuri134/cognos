"""A per-invocation Codex entry into one Cognos location. Does not edit global Codex configuration.

Credentials remain in the inherited environment, never command arguments/config literals.
MCP result receipt state persists independently of the ephemeral Codex session.
"""
from __future__ import annotations
import argparse
import json
import os
import re
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile

ENTRY_TOOLS = ("enter_space", "start_work", "note_basis", "check_changes", "hand_back",
              "list_work_items", "get_work_item", "preview_work_split", "list_attempts", "get_attempt")

DELEGATION_READ_TOOLS = ("enter_space", "note_basis", "check_changes", "list_work_items",
                         "get_work_item", "list_attempts", "get_attempt")

def delegation_tools(*, may_edit: bool, may_adopt: bool, may_manage_work: bool) -> tuple[str, ...]:
    tools = DELEGATION_READ_TOOLS
    if may_edit:
        tools += ("write_content",)
    if may_adopt:
        tools += ("adopt",)
    if may_manage_work:
        tools += ("preview_work_split", "update_work_item", "split_work_item")
    return tools


def command(workspace: Path, subject: str, tools: tuple[str, ...] = ENTRY_TOOLS) -> list[str]:
    exe = shutil.which("codex")
    if not exe:
        raise RuntimeError("找不到本机 codex，请安装并登录后再接入")
    cfg = {
        "features.shell_tool": False, "features.multi_agent": False, "features.apps": False, "features.plugins": False,
        "mcp_servers.cognos.command": sys.executable,
        "mcp_servers.cognos.args": [str(Path(__file__).with_name("server.py"))],
        "mcp_servers.cognos.env_vars": ["COGNOS_URL", "COGNOS_USER", "COGNOS_APP_PASSWORD", "COGNOS_SUBJECT",
            "COGNOS_TOOLS", "COGNOS_MCP_STATE_DIR", "COGNOS_BOUND_SPACE_TYPE", "COGNOS_BOUND_SPACE_ID", "COGNOS_BOUND_RUN_ID"],
        "mcp_servers.cognos.required": True, "mcp_servers.cognos.enabled_tools": list(tools),
        "mcp_servers.cognos.default_tools_approval_mode": "approve", "mcp_servers.cognos.startup_timeout_sec": 30,
    }
    cmd = [exe, "exec", "--ignore-user-config", "--ephemeral", "--skip-git-repo-check", "--sandbox", "read-only",
           "--json", "--color", "never", "-C", str(workspace)]
    for key, value in cfg.items():
        cmd += ["-c", key + "=" + json.dumps(value, ensure_ascii=False)]
    return cmd + ["-"]


def run(space_id: str, prompt: str, output: Path, *, subject: str = "Codex", model: str | None = None,
        timeout: int = 600, run_id: str | None = None, tools: tuple[str, ...] = ENTRY_TOOLS,
        environment: dict[str, str] | None = None):
    if not space_id.isdigit() or not subject.strip():
        raise ValueError("需要有效的位置会话编号和主体名字")
    if run_id is not None and (not re.fullmatch(r"[a-f0-9]{32}", run_id) or {"start_work", "hand_back"}.intersection(tools)):
        raise ValueError("自动委托只能使用已经登记的运行，由桥统一交回")
    env = os.environ.copy()
    env.update(environment or {})
    if not env.get("COGNOS_APP_PASSWORD"):
        raise RuntimeError("请先设置 Cognos 应用凭据，不要放进提示词或命令参数")
    output.mkdir(parents=True, exist_ok=True)
    env.update(COGNOS_SUBJECT=subject, COGNOS_TOOLS=",".join(tools), COGNOS_BOUND_SPACE_ID=space_id,
               COGNOS_BOUND_SPACE_TYPE="creation", PYTHONIOENCODING="utf-8")
    if run_id is not None:
        env["COGNOS_BOUND_RUN_ID"] = run_id
        env["COGNOS_MCP_STATE_DIR"] = str(output / "handoff-state")
    else:
        env.pop("COGNOS_BOUND_RUN_ID", None)
        env.setdefault("COGNOS_MCP_STATE_DIR", str(output / "handoff-state"))
    result = {"events": str(output / "events.jsonl"), "subject": subject, "space_id": space_id}
    with tempfile.TemporaryDirectory(prefix="cognos-codex-") as tmp:
        cmd = command(Path(tmp), subject, tools)
        final_message = Path(tmp) / "final.txt"
        if model:
            cmd[-1:-1] = ["--model", model]
        if run_id is not None:
            cmd[-1:-1] = ["--output-last-message", str(final_message)]
        with (output / "events.jsonl").open("w", encoding="utf-8") as events, (output / "stderr.log").open("w", encoding="utf-8") as errors:
            # Output may include project content; it is local evidence, never committed by this launcher.
            done = subprocess.run(cmd, input=prompt, stdout=events, stderr=errors, text=True, encoding="utf-8", env=env,
                                  cwd=tmp, timeout=timeout)
        if done.returncode:
            raise RuntimeError(f"Codex 返回状态 {done.returncode}；请核对本地日志及是否已有运行，不要直接重做")
        if run_id is not None:
            content = final_message.read_text(encoding="utf-8").strip() if final_message.is_file() else ""
            if not content:
                raise RuntimeError("Codex 没有生成最终回答，请核对本次运行记录")
            result["content"] = content
    return result


def main():
    p = argparse.ArgumentParser(description="让本机 Codex 通过 Cognos MCP 进入一个项目位置")
    p.add_argument("--space-id", required=True)
    p.add_argument("--prompt-file", type=Path, required=True)
    p.add_argument("--output-dir", type=Path, required=True)
    p.add_argument("--subject", default="Codex")
    p.add_argument("--model")
    args = p.parse_args()
    print(json.dumps(run(args.space_id, args.prompt_file.read_text(encoding="utf-8"), args.output_dir,
                         subject=args.subject, model=args.model), ensure_ascii=False))


if __name__ == "__main__":
    main()
