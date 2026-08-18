"""B2：第一 Source Adapter：DSH session store。

只做 ingress 机械同步：
scan -> 发现新/变化 session -> 生成可读 projection/archive -> 更新 registry cursor。

不复制 raw session.jsonl.zstd 进 Vault，也不 append 到第一发生场。
"""

from __future__ import annotations

import json
import os
import re
from collections import Counter
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Iterator

from boundary.registry import SourceRegistry

SOURCE_ID = "dsh"
SOURCE_TYPE = "dsh-session-store"
PROJECTION_SCHEMA = "cognos.boundary.dsh.projection.v1"


class DshSourceAdapterError(RuntimeError):
    """DSH source scan 失败。"""


def utc_now_iso() -> str:
    return datetime.now(timezone.utc).isoformat()


def iter_dsh_session_logs(dsh_home: Path | str) -> Iterator[Path]:
    root = Path(dsh_home) / "sessions"
    if not root.is_dir():
        return
    for pattern in ("*/session-*/session.jsonl.zstd", "*/session-*/session.jsonl"):
        yield from sorted(root.glob(pattern), key=lambda p: str(p))


def _read_session_events(log_path: Path) -> tuple[dict[str, Any], list[dict[str, Any]], Counter[str]]:
    try:
        from handoff.exporter import iter_jsonl_events
    except ImportError as exc:
        raise DshSourceAdapterError(
            "DSH adapter 复用 web/handoff exporter；请把 web 加入 PYTHONPATH"
        ) from exc

    events: list[dict[str, Any]] = []
    header: dict[str, Any] = {}
    counts: Counter[str] = Counter()
    for event in iter_jsonl_events(log_path):
        event_type = event.get("type") or "unknown"
        counts[event_type] += 1
        events.append(event)
        if event_type == "session" and not header:
            header = event
    return header, events, counts


def _redact_text(text: Any) -> str:
    try:
        from handoff.exporter import redact_secrets
    except ImportError:
        redact_secrets = lambda value: str(value)
    return redact_secrets(str(text or ""))


def _extract_text_messages(events: list[dict[str, Any]]) -> list[dict[str, Any]]:
    """只投影 user/message 与 assistant 的最终 text；不复制 reasoning/tool log。"""

    messages: list[dict[str, Any]] = []
    for event in events:
        event_type = event.get("type")
        if event_type not in {"user/message", "assistant/message"}:
            continue
        data = event.get("data") or {}
        message = data.get("message") or {}
        text_parts: list[str] = []
        for block in message.get("content") or []:
            if isinstance(block, dict) and block.get("type") == "text":
                text = block.get("text")
                if isinstance(text, str) and text.strip():
                    text_parts.append(text.strip())
        if not text_parts:
            continue
        messages.append(
            {
                "seq": event.get("seq"),
                "turn": data.get("turn"),
                "step": data.get("step"),
                "role": message.get("role") or event_type,
                "text": _redact_text("\n\n".join(text_parts))[:8000],
            }
        )
    return messages


def build_projection(log_path: Path, *, events: list[dict[str, Any]] | None = None) -> dict[str, Any]:
    if events is None:
        header, events, counts = _read_session_events(log_path)
    else:
        header, counts = {}, Counter()
        for event in events:
            counts[event.get("type") or "unknown"] += 1
            if event.get("type") == "session" and not header:
                header = event

    session_id = header.get("id") or log_path.parent.name
    tool_calls = [event for event in events if event.get("type") == "tool/call"]
    tool_results = [event for event in events if event.get("type") == "tool/result"]
    turn_ends = [event for event in events if event.get("type") == "turn/end"]
    request_headers = [event for event in events if event.get("type") == "request/header"]

    provider = None
    model = None
    if request_headers:
        config = (request_headers[-1].get("data") or {}).get("header", {}).get("config") or {}
        provider = config.get("provider")
        model = config.get("model")

    tool_result_refs = []
    for event in tool_results:
        data = event.get("data") or {}
        message = data.get("message") or {}
        source = message.get("source") or {}
        call_id = source.get("callId")
        is_error = bool(source.get("isError", False))
        for item in message.get("content") or []:
            if isinstance(item, dict):
                call_id = item.get("toolCallId") or call_id
                if item.get("isError") is True:
                    is_error = True
        tool_result_refs.append(
            {
                "seq": event.get("seq"),
                "turn": data.get("turn"),
                "step": data.get("step"),
                "call_id": call_id,
                "is_error": is_error,
            }
        )

    return {
        "schema": PROJECTION_SCHEMA,
        "source_id": SOURCE_ID,
        "native_session_id": session_id,
        "native_ref": str(log_path.resolve()),
        "projected_at": utc_now_iso(),
        "cwd": header.get("cwd"),
        "created_at": header.get("createdAt"),
        "provider": provider,
        "model": model,
        "event_type_counts": dict(counts),
        "text_messages": _extract_text_messages(events),
        "tool_call_count": len(tool_calls),
        "tool_call_names": sorted(
            {str((event.get("data") or {}).get("name")) for event in tool_calls}
        ),
        "tool_result_refs": tool_result_refs,
        "turn_end_reasons": [
            (event.get("data") or {}).get("reason") for event in turn_ends
        ],
        "note": "projection/archive；raw truth 仍是 native_ref 指向的 session.jsonl.zstd。",
    }


class DshSourceAdapter:
    def __init__(
        self,
        *,
        registry: SourceRegistry,
        dsh_home: Path | str | None = None,
        archive_root: Path | str,
        exclude_session_ids: set[str] | None = None,
    ) -> None:
        self.registry = registry
        self.dsh_home = Path(
            dsh_home or os.environ.get("DSH_HOME") or (Path.home() / ".dsh")
        ).expanduser()
        self.archive_root = Path(archive_root)
        self.exclude_session_ids = exclude_session_ids or set()

    def scan(self) -> dict[str, Any]:
        if not self.dsh_home.is_dir():
            raise DshSourceAdapterError(f"DSH_HOME 不存在：{self.dsh_home}")

        source = self.registry.upsert_source(
            source_id=SOURCE_ID,
            source_type=SOURCE_TYPE,
            native_location=str(self.dsh_home.resolve()),
            status="available",
        )
        discovered: list[dict[str, Any]] = []
        updated: list[dict[str, Any]] = []

        for log_path in iter_dsh_session_logs(self.dsh_home):
            try:
                header, events, counts = _read_session_events(log_path)
            except Exception as exc:
                raise DshSourceAdapterError(f"无法读取 DSH session log：{log_path}") from exc

            session_id = header.get("id") or log_path.parent.name
            if not session_id or session_id in self.exclude_session_ids:
                continue
            native_ref = str(log_path.resolve())
            stat = log_path.stat()
            cursor = {
                "size_bytes": stat.st_size,
                "mtime_ns": stat.st_mtime_ns,
                "event_count": len(events),
            }
            existing = self.registry.get_session(SOURCE_ID, session_id)
            is_new = existing is None
            is_changed = existing is not None and existing.get("cursor") != cursor
            if not is_new and not is_changed:
                continue

            projection = build_projection(log_path, events=events)
            projection_path = (
                self.archive_root / SOURCE_ID / "sessions" / f"{session_id}.projection.json"
            )
            projection_path.parent.mkdir(parents=True, exist_ok=True)
            temporary = projection_path.with_suffix(projection_path.suffix + ".tmp")
            temporary.write_text(
                json.dumps(projection, ensure_ascii=False, indent=2) + "\n",
                encoding="utf-8",
            )
            os.replace(temporary, projection_path)

            record = self.registry.record_session(
                source_id=SOURCE_ID,
                native_session_id=session_id,
                native_ref=native_ref,
                cursor=cursor,
                metadata={
                    "cwd": header.get("cwd"),
                    "created_at": header.get("createdAt"),
                    "event_count": len(events),
                    "projection_ref": str(projection_path.resolve()),
                },
            )
            item = {
                "native_session_id": session_id,
                "native_ref": native_ref,
                "changed": is_changed,
                "new": is_new,
                "event_count": len(events),
                "projection_ref": record.get("projection_ref"),
            }
            if is_new:
                discovered.append(item)
            else:
                updated.append(item)

        self.registry.upsert_source(
            source_id=SOURCE_ID,
            source_type=SOURCE_TYPE,
            native_location=str(self.dsh_home.resolve()),
            status="available",
            extra={"last_scan_at": utc_now_iso()},
        )
        return {
            "source": source,
            "discovered": discovered,
            "updated": updated,
            "scanned_session_count": len(discovered) + len(updated),
        }


def main(argv: list[str] | None = None) -> int:
    import argparse

    parser = argparse.ArgumentParser(description="B2 DSH Source Adapter：扫描并生成 ingress projection/archive")
    parser.add_argument("--dsh-home", type=Path, default=None)
    parser.add_argument("--archive-root", type=Path, required=True)
    parser.add_argument("--registry-path", type=Path, default=None)
    parser.add_argument("--exclude-session-id", action="append", default=[], dest="exclude_session_ids")
    parser.add_argument("--json", action="store_true")
    args = parser.parse_args(argv)

    archive_root = args.archive_root
    registry_path = args.registry_path or (archive_root / "registry.json")
    adapter = DshSourceAdapter(
        registry=SourceRegistry(registry_path),
        dsh_home=args.dsh_home,
        archive_root=archive_root,
        exclude_session_ids=set(args.exclude_session_ids),
    )
    try:
        result = adapter.scan()
    except DshSourceAdapterError as exc:
        print(f"error: {exc}")
        return 1
    if args.json:
        print(json.dumps(result, ensure_ascii=False, indent=2))
    else:
        print(
            f"source={result['source']['source_id']} "
            f"discovered={len(result['discovered'])} updated={len(result['updated'])}"
        )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
