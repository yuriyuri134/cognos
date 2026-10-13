"""E2：DSH headless runner。

第一版只创建新会话，不 resume。
runner 是独立脚本；HTTP 请求内不直接执行 dsh。
"""

from __future__ import annotations

import json
import os
import re
import shutil
import subprocess
import time
from dataclasses import dataclass
from pathlib import Path
from typing import Any

from .envelope import HandoffEnvelopeError, build_dsh_prompt, load_envelope


class DshRunnerError(RuntimeError):
    """无法启动 DSH、执行后没有找到新 session log、非零退出码、或缺少 completed turn/end。"""


# Windows 目录名非法字符（含盘符冒号）；同时兼容 `<>:"/\\|?*` 与控制字符
_INVALID_SLUG_CHARS = re.compile(r"[^A-Za-z0-9._-]+")


@dataclass(frozen=True)
class DshRunResult:
    session_id: str
    session_log_path: str
    dsh_home: str
    profile: str
    exit_code: int
    started_at: str
    finished_at: str
    duration_seconds: float


def encode_workspace_dir(working_directory: Path | str) -> str:
    """按当前 DSH 规则编码 workspace 目录名，并兼容 Windows 盘符冒号。

    `/mnt/c/Users/lnp/Documents/Playground/cognos`
    -> `--mnt-c-Users-lnp-Documents-Playground-cognos--`

    `C:\\Users\\lnp\\...\\cognos`（Windows）
    -> `--C-Users-lnp-...-cognos--`（原实现在 Windows 会留下非法冒号 `C:`）
    """

    path = Path(working_directory)
    normalized = path.as_posix().strip("/")
    # 先整体清洗 Windows 非法字符，再按既有规则把 `/` 变成 `-`
    normalized = _INVALID_SLUG_CHARS.sub("-", normalized).strip("-")
    return f"--{normalized.replace('/', '-')}--"


def scan_session_logs(dsh_home: Path | str) -> dict[str, int]:
    """返回 session log path -> mtime_ns，用于执行前后对比。"""

    root = Path(dsh_home) / "sessions"
    found: dict[str, int] = {}
    if not root.exists():
        return found
    for pattern in ("*/session-*/session.jsonl.zstd", "*/session-*/session.jsonl"):
        for log_path in root.glob(pattern):
            try:
                found[str(log_path)] = log_path.stat().st_mtime_ns
            except OSError:
                found[str(log_path)] = 0
    return found


def _decode_session_header(log_path: Path) -> dict[str, Any]:
    """只读第一条 session 行；不把完整 tool log 载入 runner。"""

    from .exporter import iter_jsonl_events

    for event in iter_jsonl_events(log_path):
        if event.get("type") == "session":
            return event
    return {}


def _session_completed_turn_end(log_path: Path) -> bool:
    """流式检查 session log 是否存在 reason=completed 的 turn/end；找到即返回。"""

    from .exporter import iter_jsonl_events

    for event in iter_jsonl_events(log_path):
        if event.get("type") == "turn/end":
            reason = (event.get("data") or {}).get("reason") or {}
            if reason.get("kind") == "completed":
                return True
    return False


def _pick_new_session(
    new_logs: list[tuple[str, int]],
    working_directory: str,
) -> tuple[str, dict[str, Any]] | None:
    """优先选择 session header 中 cwd 与 envelope working_directory 一致的新日志。"""

    candidates: list[tuple[str, dict[str, Any]]] = []
    for log_path, _ in new_logs:
        path = Path(log_path)
        fallback = {"id": path.parent.name, "cwd": None}
        try:
            header = _decode_session_header(path)
        except Exception:
            header = fallback
        if not header.get("id"):
            header["id"] = path.parent.name
        candidates.append((log_path, header))

    working_directory = str(Path(working_directory).resolve())
    matching = []
    for log_path, header in candidates:
        header_cwd = str(Path(str(header.get("cwd", ""))).resolve()) if header.get("cwd") else ""
        if header_cwd == working_directory:
            matching.append((log_path, header))
    if len(matching) == 1:
        return matching[0]
    # Never guess among multiple new sessions; an incorrect execution
    # attribution is worse than an explicit unknown result.
    return None


def run_envelope(
    envelope_path: Path | str,
    *,
    dsh_home: Path | str | None = None,
    dsh_bin: str | None = None,
    profile: str = "headless",
    timeout: float | None = None,
    extra_env: dict[str, str] | None = None,
    extra_args: list[str] | None = None,
    approval_policy: str | None = "never",
) -> DshRunResult:
    """读取 envelope，创建（不 resume）一个 DSH headless session。"""

    envelope_path = Path(envelope_path)
    envelope = load_envelope(envelope_path)
    working_directory = Path(envelope["working_directory"]).expanduser()
    if not working_directory.is_dir():
        raise DshRunnerError(f"envelope working_directory 不存在：{working_directory}")

    dsh_home = Path(dsh_home or os.environ.get("DSH_HOME") or (Path.home() / ".dsh")).expanduser()
    dsh_bin = dsh_bin or os.environ.get("DSH_BIN") or shutil.which("dsh")
    if not dsh_bin:
        raise DshRunnerError("找不到 dsh 可执行文件；请设置 DSH_BIN 或把 dsh 放入 PATH")

    prompt = build_dsh_prompt(envelope)
    env = os.environ.copy()
    env["DSH_HOME"] = str(dsh_home)
    env.setdefault("DSH_PERMISSION_MODE", "workspace-write")
    if extra_env:
        env.update(extra_env)

    command = [str(dsh_bin), "--profile", profile]
    if approval_policy == "never" and profile == "headless":
        approval_patch = Path(__file__).resolve().parent / "headless-approval-never.patch.yml"
        if approval_patch.exists():
            command.extend(["--patch", str(approval_patch)])
    if extra_args:
        command.extend(extra_args)
    command.append(prompt)

    before = scan_session_logs(dsh_home)
    started_at = time.time()
    started_iso = _iso_now()

    try:
        completed = subprocess.run(
            command,
            cwd=str(working_directory),
            env=env,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            timeout=timeout,
            check=False,
        )
    except FileNotFoundError as exc:
        raise DshRunnerError(f"dsh 可执行文件不存在：{dsh_bin}") from exc
    except subprocess.TimeoutExpired as exc:
        raise DshRunnerError(f"DSH headless 超时（{timeout} 秒）") from exc
    finally:
        pass

    finished_at = time.time()
    after = scan_session_logs(dsh_home)
    new_logs = [
        (path, mtime)
        for path, mtime in after.items()
        if path not in before or mtime > int(started_at * 1_000_000_000)
    ]
    new_logs.sort(key=lambda item: item[1])

    picked = _pick_new_session(new_logs, envelope["working_directory"])
    if picked is None:
        stdout_tail = (completed.stdout or "").strip().splitlines()[-20:]
        stderr_tail = (completed.stderr or "").strip().splitlines()[-20:]
        raise DshRunnerError(
            "DSH 执行结束，但未找到本次新产生的 session log。\n"
            f"exit_code={completed.returncode}\n"
            f"stdout_tail={json.dumps(stdout_tail, ensure_ascii=False)}\n"
            f"stderr_tail={json.dumps(stderr_tail, ensure_ascii=False)}"
        )

    log_path, header = picked
    session_id = str(header.get("id") or Path(log_path).parent.name)
    stdout_tail = (completed.stdout or "").strip().splitlines()[-20:]
    stderr_tail = (completed.stderr or "").strip().splitlines()[-20:]

    if completed.returncode != 0:
        raise DshRunnerError(
            "DSH 以非零退出码结束，本次 run 视为失败。\n"
            f"session_id={session_id}\n"
            f"exit_code={completed.returncode}\n"
            f"stdout_tail={json.dumps(stdout_tail, ensure_ascii=False)}\n"
            f"stderr_tail={json.dumps(stderr_tail, ensure_ascii=False)}"
        )

    if not _session_completed_turn_end(Path(log_path)):
        raise DshRunnerError(
            "DSH 已退出，但 session log 中没有 reason=completed 的 turn/end，本次 run 视为失败。\n"
            f"session_id={session_id}\n"
            f"exit_code={completed.returncode}\n"
            f"stdout_tail={json.dumps(stdout_tail, ensure_ascii=False)}\n"
            f"stderr_tail={json.dumps(stderr_tail, ensure_ascii=False)}"
        )

    return DshRunResult(
        session_id=session_id,
        session_log_path=str(Path(log_path).resolve()),
        dsh_home=str(dsh_home.resolve()),
        profile=profile,
        exit_code=completed.returncode,
        started_at=started_iso,
        finished_at=_iso_now(),
        duration_seconds=round(finished_at - started_at, 3),
    )


def _iso_now() -> str:
    from datetime import datetime, timezone

    return datetime.now(timezone.utc).isoformat()


def main(argv: list[str] | None = None) -> int:
    import argparse

    parser = argparse.ArgumentParser(description="E2 DSH headless runner（只创建，不 resume）")
    parser.add_argument("envelope", type=Path, help="E1 handoff envelope JSON 文件")
    parser.add_argument("--dsh-home", type=Path, default=None, help="DSH_HOME；默认 $DSH_HOME 或 ~/.dsh")
    parser.add_argument("--dsh", default=None, help="dsh 可执行文件；默认 PATH 中的 dsh")
    parser.add_argument("--profile", default="headless")
    parser.add_argument("--timeout", type=float, default=None)
    parser.add_argument("--patch", action="append", default=[], dest="patches", help="额外 --patch overlay；可重复")
    parser.add_argument("--approval-policy", default="never", choices=["never", "ask"])
    parser.add_argument("--json", action="store_true", help="输出 JSON 而不是人类可读文本")
    args = parser.parse_args(argv)

    try:
        result = run_envelope(
            args.envelope,
            dsh_home=args.dsh_home,
            dsh_bin=args.dsh,
            profile=args.profile,
            timeout=args.timeout,
            extra_args=[arg for patch in args.patches for arg in ("--patch", patch)],
            approval_policy=args.approval_policy,
        )
    except (DshRunnerError, HandoffEnvelopeError) as exc:
        if args.json:
            print(json.dumps({"status": "error", "error": str(exc)}, ensure_ascii=False))
        else:
            print(f"error: {exc}")
        return 1

    payload = {
        "status": "ok",
        "session_id": result.session_id,
        "session_log_path": result.session_log_path,
        "dsh_home": result.dsh_home,
        "profile": result.profile,
        "exit_code": result.exit_code,
        "started_at": result.started_at,
        "finished_at": result.finished_at,
        "duration_seconds": result.duration_seconds,
    }
    if args.json:
        print(json.dumps(payload, ensure_ascii=False, indent=2))
    else:
        print(f"session_id: {payload['session_id']}")
        print(f"session_log_path: {payload['session_log_path']}")
        print(f"exit_code: {payload['exit_code']}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
