"""E4 CLI：把 E3 的 Vault-safe payload 作为一次普通 occurrence 回第一发生场。

只通过 HTTP API 追加，不直接写 Vault 文件，不改写旧行。
"""

from __future__ import annotations

import argparse
import json
from pathlib import Path
from typing import Any

import httpx


def build_request_payload(vault_result: dict[str, Any]) -> dict[str, Any]:
    changed_files = vault_result.get("changed_files") or []
    test_provenance = vault_result.get("test_provenance") or {}
    test_runs = test_provenance.get("test_runs") or []
    commands_run = [item.get("command") for item in test_runs if item.get("command")]
    errors = [
        item.get("content_excerpt")
        for item in vault_result.get("tool_result_refs") or []
        if item.get("is_error")
    ]
    return {
        "handoff_id": vault_result["handoff_id"],
        "agent_name": vault_result.get("agent_name", "DSH"),
        "agent_response": format_content_from_vault(vault_result),
        "changed_files": changed_files,
        "commands_run": commands_run,
        "errors": errors,
        "session_ref": vault_result.get("session_ref"),
        "related_occurrence_ids": vault_result.get("related_occurrence_ids") or [],
        "git_provenance": vault_result.get("git_provenance"),
        "test_provenance": vault_result.get("test_provenance"),
    }


def format_content_from_vault(vault_result: dict[str, Any]) -> str:
    session_ref = vault_result.get("session_ref") or {}
    test_provenance = vault_result.get("test_provenance") or {}
    git_provenance = vault_result.get("git_provenance") or {}
    provider_model = vault_result.get("provider_model") or {}
    lines = [
        "[Cognos Handoff Result R]",
        f"handoff_id: {vault_result.get('handoff_id')}",
        f"related_occurrence_ids: {json.dumps(vault_result.get('related_occurrence_ids') or [], ensure_ascii=False)}",
        f"session_ref: {session_ref.get('session_id')}",
        f"session_log_path: {session_ref.get('session_log_path')}",
        f"cwd: {vault_result.get('cwd')}",
        f"provider/model: {provider_model.get('provider')} / {provider_model.get('model')}",
        f"test_status: {test_provenance.get('status')}",
        f"git_head: {git_provenance.get('head')}",
        f"changed_files: {json.dumps(vault_result.get('changed_files') or [], ensure_ascii=False)}",
        f"tool_call_count: {vault_result.get('tool_call_count')}",
        f"tool_result_count: {vault_result.get('tool_result_count')}",
        "",
        "[final_assistant_text]",
        vault_result.get("final_assistant_text") or "(无最终 text)",
    ]
    return "\n".join(lines)


def post_result(
    vault_result: dict[str, Any],
    *,
    server_url: str = "http://127.0.0.1:8765",
    timeout: float = 30.0,
) -> dict[str, Any]:
    payload = build_request_payload(vault_result)
    response = httpx.post(
        f"{server_url.rstrip('/')}/api/handoff/result",
        json=payload,
        timeout=timeout,
    )
    response.raise_for_status()
    return response.json()


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="E4 把 Result R 回第一发生场")
    parser.add_argument("vault_payload", type=Path, help="E3 生成的 Vault-safe payload JSON")
    parser.add_argument("--server-url", default="http://127.0.0.1:8765")
    parser.add_argument("--timeout", type=float, default=30.0)
    args = parser.parse_args(argv)

    try:
        payload = json.loads(args.vault_payload.read_text(encoding="utf-8"))
        result = post_result(payload, server_url=args.server_url, timeout=args.timeout)
    except (OSError, json.JSONDecodeError, httpx.HTTPError) as exc:
        print(f"error: {exc}")
        return 1
    print(json.dumps(result, ensure_ascii=False, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
