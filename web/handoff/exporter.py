"""E3：DSH session log -> Cognos 可观察 Result。

从 session.jsonl.zstd 机械提取，不做模型二次总结。
git/test provenance 也由本文件机械采集。
"""

from __future__ import annotations

import json
import re
import subprocess
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Iterator

MAX_RESULT_CONTENT_CHARS = 4000
MAX_FINAL_ASSISTANT_CHARS = 12000
ZSTD_MAGIC = b"\x28\xb5\x2f\xfd"


class DshExportError(RuntimeError):
    """session log 无法读取或缺少最小结构。"""


def _utc_now_iso() -> str:
    return datetime.now(timezone.utc).isoformat()


def iter_jsonl_events(log_path: Path | str) -> Iterator[dict[str, Any]]:
    """流式读取 .jsonl 或 .jsonl.zstd；不会把整个 tool log 一次性载入内存。"""

    path = Path(log_path)
    if not path.exists():
        raise DshExportError(f"session log 不存在：{path}")

    suffix = path.suffix.lower()
    if suffix == ".jsonl":
        with path.open("r", encoding="utf-8-sig") as stream:
            for line_number, line in enumerate(stream, start=1):
                line = line.strip()
                if not line:
                    continue
                try:
                    yield json.loads(line)
                except json.JSONDecodeError as exc:
                    raise DshExportError(f"session log 第 {line_number} 行不是 JSON：{path}") from exc
        return

    if suffix == ".zstd" or path.name.endswith(".jsonl.zstd"):
        try:
            import zstandard
        except ImportError as exc:
            raise DshExportError(
                "读取 .jsonl.zstd 需要安装 zstandard：pip install zstandard"
            ) from exc
        with path.open("rb") as stream:
            reader = zstandard.ZstdDecompressor().stream_reader(stream)
            import io

            text_stream = io.TextIOWrapper(reader, encoding="utf-8")
            try:
                for line_number, line in enumerate(text_stream, start=1):
                    line = line.strip()
                    if not line:
                        continue
                    try:
                        yield json.loads(line)
                    except json.JSONDecodeError as exc:
                        raise DshExportError(
                            f"session log 第 {line_number} 行不是 JSON：{path}"
                        ) from exc
            finally:
                text_stream.detach()
        return

    # 最后按 magic 自动识别，避免依赖扩展名。
    with path.open("rb") as stream:
        head = stream.read(4)
    if head.startswith(ZSTD_MAGIC):
        import io
        import zstandard

        with path.open("rb") as stream:
            reader = zstandard.ZstdDecompressor().stream_reader(stream)
            text_stream = io.TextIOWrapper(reader, encoding="utf-8")
            try:
                for line_number, line in enumerate(text_stream, start=1):
                    line = line.strip()
                    if not line:
                        continue
                    try:
                        yield json.loads(line)
                    except json.JSONDecodeError as exc:
                        raise DshExportError(
                            f"session log 第 {line_number} 行不是 JSON：{path}"
                        ) from exc
            finally:
                text_stream.detach()
        return

    raise DshExportError(f"不支持的 session log 格式：{path}")


def _content_to_text(blocks: Any) -> str:
    """从 DSH 的 content block 机械提取纯文本，不解释内容。"""

    if isinstance(blocks, str):
        return blocks
    parts: list[str] = []

    def walk(node: Any) -> None:
        if isinstance(node, str):
            parts.append(node)
            return
        if isinstance(node, dict):
            for key, value in node.items():
                if key in {"text", "content"} and isinstance(value, str):
                    parts.append(value)
                elif isinstance(value, (dict, list)):
                    walk(value)
        elif isinstance(node, list):
            for item in node:
                walk(item)

    walk(blocks)
    return "".join(parts).strip()


def _parse_arguments(arguments: Any) -> tuple[Any, str]:
    if isinstance(arguments, str):
        raw = arguments
        try:
            return json.loads(arguments), raw
        except json.JSONDecodeError:
            return None, raw
    return arguments, json.dumps(arguments, ensure_ascii=False)


def _extract_tool_result(event: dict[str, Any]) -> dict[str, Any] | None:
    data = event.get("data") or {}
    message = data.get("message") or {}
    source = message.get("source") or {}
    call_id = source.get("callId")
    is_error = bool(source.get("isError", False))
    content_text = ""
    for item in message.get("content") or []:
        if not isinstance(item, dict):
            continue
        if item.get("isError") is True:
            is_error = True
        if not call_id:
            call_id = item.get("toolCallId") or item.get("callId")
        content_text += _content_to_text(item.get("content", ""))
    if not call_id and not content_text:
        return None
    return {
        "seq": event.get("seq"),
        "turn": data.get("turn"),
        "step": data.get("step"),
        "callId": call_id,
        "isError": is_error,
        "content": content_text,
        # 内部兼容字段
        "call_id": call_id,
        "is_error": is_error,
    }


def export_session(
    log_path: Path | str,
    *,
    working_directory: str | None = None,
    handoff_id: str | None = None,
    executor: str = "dsh",
    max_result_content_chars: int = MAX_RESULT_CONTENT_CHARS,
) -> dict[str, Any]:
    log_path = Path(log_path)

    session_header: dict[str, Any] = {}
    request_header: dict[str, Any] = {}
    last_assistant_with_text: dict[str, Any] | None = None
    tool_calls: list[dict[str, Any]] = []
    tool_results: list[dict[str, Any]] = []
    turn_end_reasons: list[dict[str, Any]] = []

    for event in iter_jsonl_events(log_path):
        event_type = event.get("type")
        if event_type == "session" and not session_header:
            session_header = event
        elif event_type == "request/header":
            data = event.get("data") or {}
            header = data.get("header") or {}
            config = header.get("config") or {}
            request_header = {
                "provider": config.get("provider"),
                "model": config.get("model"),
                "system": (header.get("system") or "")[:500],
            }
        elif event_type == "assistant/message":
            data = event.get("data") or {}
            message = data.get("message") or {}
            source = message.get("source") or {}
            if not request_header.get("provider") and source.get("provider"):
                request_header["provider"] = source.get("provider")
            if not request_header.get("model") and source.get("model"):
                request_header["model"] = source.get("model")
            text_parts: list[str] = []
            for block in message.get("content") or []:
                if isinstance(block, dict) and block.get("type") == "text":
                    text = block.get("text", "")
                    if isinstance(text, str) and text.strip():
                        text_parts.append(text.strip())
            final_text = "\n\n".join(text_parts).strip()
            if final_text:
                last_assistant_with_text = {
                    "seq": event.get("seq"),
                    "turn": data.get("turn"),
                    "step": data.get("step"),
                    "text": final_text,
                }
        elif event_type == "tool/call":
            data = event.get("data") or {}
            parsed, raw = _parse_arguments(data.get("arguments"))
            tool_calls.append(
                {
                    "seq": event.get("seq"),
                    "turn": data.get("turn"),
                    "step": data.get("step"),
                    "call_id": data.get("callId"),
                    "name": data.get("name"),
                    "arguments": parsed if parsed is not None else raw,
                    "arguments_raw": raw,
                }
            )
        elif event_type == "tool/result":
            extracted = _extract_tool_result(event)
            if extracted:
                text = extracted["content"]
                excerpt = text[:max_result_content_chars]
                if len(text) > max_result_content_chars:
                    excerpt += f"\n...[truncated {len(text) - max_result_content_chars} chars]"
                extracted["content_excerpt"] = excerpt
                extracted["content_chars"] = len(text)
                tool_results.append(extracted)
        elif event_type == "turn/end":
            data = event.get("data") or {}
            turn_end_reasons.append(
                {
                    "seq": event.get("seq"),
                    "turn": data.get("turn"),
                    "reason": data.get("reason"),
                }
            )

    session_id = session_header.get("id") or log_path.parent.name
    cwd = session_header.get("cwd") or working_directory
    if not session_id:
        raise DshExportError(f"session log 缺少 session header：{log_path}")

    provider_model = {
        "provider": request_header.get("provider"),
        "model": request_header.get("model"),
    }

    result: dict[str, Any] = {
        "schema": "cognos.handoff.result_export.v1",
        "extracted_at": _utc_now_iso(),
        "handoff_id": handoff_id,
        "executor": executor,
        "session_ref": {
            "session_id": session_id,
            "native_log_path": str(log_path.resolve()),
            "session_log_path": str(log_path.resolve()),
        },
        "cwd": cwd,
        "provider": provider_model["provider"],
        "model": provider_model["model"],
        "provider_model": provider_model,
        "provider/model": provider_model,
        "final_assistant_text": last_assistant_with_text["text"]
        if last_assistant_with_text
        else "",
        "tool/call": tool_calls,
        "tool/result": tool_results,
        "turn/end.reason": turn_end_reasons,
        "raw_log": {
            "source": "DSH_HOME session.jsonl.zstd",
            "note": "完整 raw tool log 不复制进 Vault；这里只保存引用。",
        },
    }

    working_dir = working_directory or cwd
    if working_dir:
        git_provenance = collect_git_provenance(working_dir)
        test_provenance = collect_test_provenance(
            tool_calls,
            tool_results,
            claimed_text=result["final_assistant_text"],
            session_log_path=str(log_path.resolve()),
        )
        result["git_provenance"] = git_provenance
        result["test_provenance"] = test_provenance
        result["commands_run"] = [
            redact_secrets(command)
            for command in (_tool_call_command(call) for call in tool_calls)
            if command
        ]
        result["tool_errors"] = [
            {
                "seq": item.get("seq"),
                "callId": item.get("callId"),
                "content_excerpt": redact_secrets(item.get("content_excerpt", ""))[:MAX_RESULT_CONTENT_CHARS],
            }
            for item in tool_results
            if item.get("isError") or item.get("is_error")
        ]
        result["files_changed"] = changed_files_from_git(git_provenance)
        result["tests"] = test_provenance.get("test_runs") or []
        result["diff_ref"] = git_provenance.get("head")
    return result


def _run_git(cwd: Path, args: list[str]) -> dict[str, Any]:
    command = ["git", "-C", str(cwd), *args]
    try:
        completed = subprocess.run(
            command,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            timeout=30,
            check=False,
        )
    except (OSError, subprocess.TimeoutExpired) as exc:
        return {
            "command": " ".join(command),
            "exit_code": None,
            "stdout": "",
            "stderr": str(exc),
        }
    return {
        "command": " ".join(command),
        "exit_code": completed.returncode,
        "stdout": completed.stdout,
        "stderr": completed.stderr,
    }


def collect_git_provenance(working_directory: Path | str) -> dict[str, Any]:
    cwd = Path(working_directory).expanduser().resolve()
    if not cwd.exists():
        return {"working_directory": str(cwd), "available": False, "error": "working_directory 不存在"}

    rev_parse_root = _run_git(cwd, ["rev-parse", "--show-toplevel"])
    git_root = rev_parse_root["stdout"].strip() if rev_parse_root["exit_code"] == 0 else None
    head_result = _run_git(cwd, ["rev-parse", "HEAD"])
    status_result = _run_git(cwd, ["status", "--porcelain"])
    diff_result = _run_git(cwd, ["diff", "--name-only"])

    provenance: dict[str, Any] = {
        "working_directory": str(cwd),
        "available": bool(git_root),
        "git_root": git_root,
        "head": head_result["stdout"].strip() if head_result["exit_code"] == 0 else None,
        "commands": {
            "rev_parse_head": head_result,
            "status_porcelain": status_result,
            "diff_name_only": diff_result,
        },
        "observations": {
            "status_porcelain": status_result["stdout"],
            "diff_name_only": diff_result["stdout"],
        },
        "note": "git diff 不包含 untracked 文件；status --porcelain 才是执行后的可观察状态全集。",
    }

    if git_root:
        root = Path(git_root)
        try:
            rel_cwd = cwd.relative_to(root)
        except ValueError:
            rel_cwd = None
        if rel_cwd is not None and rel_cwd != Path("."):
            scoped_status = _run_git(root, ["status", "--porcelain", "--", str(rel_cwd)])
            scoped_diff = _run_git(root, ["diff", "--name-only", "--", str(rel_cwd)])
            provenance["commands"]["status_scoped"] = scoped_status
            provenance["commands"]["diff_scoped"] = scoped_diff
            provenance["observations"]["status_scoped"] = scoped_status["stdout"]
            provenance["observations"]["diff_scoped"] = scoped_diff["stdout"]
            provenance["scoped_path"] = str(rel_cwd)
    return provenance


def _tool_call_command(call: dict[str, Any]) -> str | None:
    if call.get("name") not in {"bash", "shell", "terminal", "run"}:
        return None
    arguments = call.get("arguments")
    if isinstance(arguments, str):
        return arguments
    if isinstance(arguments, dict):
        command = arguments.get("command") or arguments.get("cmd")
        return command if isinstance(command, str) else None
    return None


_TEST_INVOCATION_RE = re.compile(
    r"(?:^|[;&|]\s*)(?:[A-Za-z0-9_./-]*python(?:3(?:\.\d+)?)?\s+-m\s+(?:pytest|unittest)\b|\b(?:pytest|unittest)\b)",
    re.IGNORECASE,
)


def _looks_like_test_command(command: str) -> bool:
    return bool(_TEST_INVOCATION_RE.search(command or ""))


def _parse_pytest_summary(output: str) -> dict[str, int]:
    passed = 0
    failed = 0
    errors = 0
    for value in re.findall(r"\b(\d+) passed\b", output):
        passed = max(passed, int(value))
    for value in re.findall(r"\b(\d+) failed\b", output):
        failed = max(failed, int(value))
    for value in re.findall(r"\b(\d+) errors?\b", output):
        errors = max(errors, int(value))
    return {"passed": passed, "failed": failed, "errors": errors}


def collect_test_provenance(
    tool_calls: list[dict[str, Any]],
    tool_results: list[dict[str, Any]],
    *,
    claimed_text: str,
    session_log_path: str,
) -> dict[str, Any]:
    results_by_call_id: dict[str, dict[str, Any]] = {}
    for result in tool_results:
        call_id = result.get("call_id")
        if call_id and call_id not in results_by_call_id:
            results_by_call_id[call_id] = result

    test_runs: list[dict[str, Any]] = []
    for call in tool_calls:
        command = _tool_call_command(call)
        if not command or not _looks_like_test_command(command):
            continue
        result = results_by_call_id.get(call.get("call_id"))
        output = result.get("content", "") if result else ""
        exit_match = re.search(r"\[exit code:\s*(\d+)\]|exit code[: ]?\s*(\d+)", output, re.I)
        exit_code = int(exit_match.group(1) or exit_match.group(2)) if exit_match else None
        summary = _parse_pytest_summary(output)
        if summary["failed"] > 0 or summary["errors"] > 0:
            actual_status = "tests_failed" if summary["failed"] > 0 else "tests_had_errors"
        elif summary["passed"] > 0:
            actual_status = "tests_passed"
        elif re.search(r"\bOK\b", output):
            actual_status = "tests_passed"
        else:
            actual_status = "test_command_ran_without_parseable_summary"

        test_runs.append(
            {
                "command": redact_secrets(command),
                "exit_code": exit_code,
                "tool_call_seq": call.get("seq"),
                "tool_result_seq": result.get("seq") if result else None,
                "call_id": call.get("call_id"),
                "actual_status": actual_status,
                "pytest_summary": summary,
                "output_reference": {
                    "session_log_path": session_log_path,
                    "seq": result.get("seq") if result else None,
                    "content_chars": result.get("content_chars", 0) if result else 0,
                    "content_excerpt": redact_secrets(result.get("content_excerpt", ""))[:MAX_RESULT_CONTENT_CHARS]
                    if result
                    else "",
                },
            }
        )

    observed_statuses = [item["actual_status"] for item in test_runs]
    if any(status in {"tests_failed", "tests_had_errors"} for status in observed_statuses):
        overall = "tests_failed"
    elif "tests_passed" in observed_statuses:
        overall = "tests_passed"
    elif observed_statuses:
        overall = "test_command_ran_without_parseable_summary"
    else:
        overall = "no_tests_run"

    claim_pattern = re.compile(
        r"(?:tests?|测试)[^\n]{0,60}(?:passed|通过)|(?:passed|通过)[^\n]{0,60}(?:tests?|测试)",
        re.IGNORECASE,
    )
    claimed_by_assistant = bool(claim_pattern.search(claimed_text or ""))

    if overall == "no_tests_run" and claimed_by_assistant:
        overall = "claimed_by_dsh_without_observed_test_result"

    return {
        "status": overall,
        "test_command_count": len(test_runs),
        "actual_observed": observed_statuses,
        "claimed_by_assistant": claimed_by_assistant,
        "test_runs": test_runs,
        "note": "status 只来自真实 test command 与 tool/result 的机械解析；不使用模型自我评价。",
    }


_SECRET_PATTERNS = [
    re.compile(r"(?i)\b(bearer)\s+[A-Za-z0-9_\-./+=]{8,}"),
    re.compile(r"(?i)\b(authorization)\s*[:=]\s*[^\n]{8,}"),
    re.compile(r"\bAIza[0-9A-Za-z_-]{20,}\b"),
    re.compile(r"\bsk-[A-Za-z0-9_-]{16,}\b"),
    re.compile(r"(?i)\b(api[_-]?key|token|secret|password|passwd)\b\s*[=:]\s*[\"']?[A-Za-z0-9_\-./+=]{6,}", re.I),
]


def redact_secrets(text: str) -> str:
    redacted = text
    for pattern in _SECRET_PATTERNS:
        redacted = pattern.sub(lambda match: match.group(0)[: match.group(0).find("=") + 1] + "<redacted>" if "=" in match.group(0) else "<redacted>", redacted)
    return redacted


def changed_files_from_git(git_provenance: dict[str, Any] | None) -> list[str]:
    if not git_provenance:
        return []
    observations = git_provenance.get("observations") or {}
    raw = observations.get("status_scoped") or observations.get("status_porcelain") or ""
    files: list[str] = []
    for line in raw.splitlines():
        if not line.strip():
            continue
        path = line[3:].strip().split(" -> ")[-1].strip('"')
        if path:
            files.append(path)
    return files


def build_vault_result(
    export: dict[str, Any],
    *,
    handoff_id: str,
    related_occurrence_ids: list[str] | None = None,
    agent_name: str = "DSH",
) -> dict[str, Any]:
    """从 export 生成回第一发生场的薄 payload；不复制完整 tool log。"""

    session_ref = export.get("session_ref") or {}
    git_provenance = export.get("git_provenance") or {}
    test_provenance = export.get("test_provenance") or {}
    tool_calls = export.get("tool/call") or []
    tool_results = export.get("tool/result") or []

    tool_call_names: list[str] = []
    for call in tool_calls:
        name = call.get("name")
        if isinstance(name, str) and name not in tool_call_names:
            tool_call_names.append(name)

    tool_result_refs: list[dict[str, Any]] = []
    for result in tool_results:
        tool_result_refs.append(
            {
                "seq": result.get("seq"),
                "call_id": result.get("call_id"),
                "is_error": result.get("is_error"),
                "content_chars": result.get("content_chars"),
                "content_excerpt": redact_secrets(result.get("content_excerpt", ""))[
                    :MAX_RESULT_CONTENT_CHARS
                ],
            }
        )

    final_text = redact_secrets(export.get("final_assistant_text") or "")
    if len(final_text) > MAX_FINAL_ASSISTANT_CHARS:
        final_text = final_text[:MAX_FINAL_ASSISTANT_CHARS] + (
            f"\n...[truncated {len(final_text) - MAX_FINAL_ASSISTANT_CHARS} chars]"
        )

    vault_test_provenance = json.loads(json.dumps(test_provenance, ensure_ascii=False))
    for test_run in vault_test_provenance.get("test_runs") or []:
        if isinstance(test_run.get("command"), str):
            test_run["command"] = redact_secrets(test_run["command"])

    return {
        "schema": "cognos.handoff.result.vault.v1",
        "handoff_id": handoff_id,
        "related_occurrence_ids": list(related_occurrence_ids or []),
        "agent_name": agent_name,
        "session_ref": session_ref,
        "cwd": export.get("cwd"),
        "provider_model": export.get("provider_model"),
        "final_assistant_text": final_text,
        "tool_call_count": len(tool_calls),
        "tool_call_names": tool_call_names,
        "tool_result_count": len(tool_results),
        "tool_result_refs": tool_result_refs,
        "turn_end_reasons": export.get("turn/end.reason") or [],
        "git_provenance": git_provenance,
        "test_provenance": vault_test_provenance,
        "changed_files": changed_files_from_git(git_provenance),
        "raw_log_ref": session_ref.get("session_log_path"),
        "note": "完整 DSH tool log 不进入 Vault；仅保留 session_ref 与机械提取的可观察结果。",
    }


def format_agent_response(vault_result: dict[str, Any]) -> str:
    """机械生成 Result occurrence 的 content，不生成“执行成功”结论。"""

    session_ref = vault_result.get("session_ref") or {}
    test_provenance = vault_result.get("test_provenance") or {}
    git_provenance = vault_result.get("git_provenance") or {}
    lines = [
        "[Cognos Handoff Result R]",
        f"handoff_id: {vault_result.get('handoff_id')}",
        f"session_ref: {session_ref.get('session_id')} ({session_ref.get('session_log_path')})",
        f"cwd: {vault_result.get('cwd')}",
        f"provider/model: {vault_result.get('provider_model')}",
        f"test_status: {test_provenance.get('status')}",
        f"git_head: {git_provenance.get('head')}",
        f"changed_files: {json.dumps(vault_result.get('changed_files') or [], ensure_ascii=False)}",
        "",
        "[final_assistant_text]",
        vault_result.get("final_assistant_text") or "(无最终 text)",
    ]
    return "\n".join(lines)


def main(argv: list[str] | None = None) -> int:
    import argparse

    parser = argparse.ArgumentParser(description="E3 从 DSH session log 导出可观察结果")
    parser.add_argument("--session-log", required=True, type=Path)
    parser.add_argument("--working-directory", default=None, type=Path)
    parser.add_argument("--handoff-id", default=None, help="生成 Vault-safe payload 时使用")
    parser.add_argument("--related-occurrence-id", action="append", default=[], dest="related_ids")
    parser.add_argument("--out", type=Path, default=None, help="导出完整 JSON 的路径")
    parser.add_argument("--vault-payload-out", type=Path, default=None, help="导出 Vault-safe payload 的路径")
    args = parser.parse_args(argv)

    try:
        export = export_session(
            args.session_log,
            working_directory=str(args.working_directory) if args.working_directory else None,
        )
    except DshExportError as exc:
        print(f"error: {exc}")
        return 1

    if args.out:
        args.out.parent.mkdir(parents=True, exist_ok=True)
        args.out.write_text(
            json.dumps(export, ensure_ascii=False, indent=2) + "\n",
            encoding="utf-8",
        )
        print(f"export: {args.out}")

    if args.vault_payload_out or args.handoff_id:
        handoff_id = args.handoff_id or ""
        payload = build_vault_result(export, handoff_id=handoff_id, related_occurrence_ids=args.related_ids)
        if args.vault_payload_out:
            args.vault_payload_out.parent.mkdir(parents=True, exist_ok=True)
            args.vault_payload_out.write_text(
                json.dumps(payload, ensure_ascii=False, indent=2) + "\n",
                encoding="utf-8",
            )
            print(f"vault_payload: {args.vault_payload_out}")
        else:
            print(json.dumps(payload, ensure_ascii=False, indent=2))
    elif not args.out:
        print(json.dumps(export, ensure_ascii=False, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
