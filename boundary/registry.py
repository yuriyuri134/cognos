"""B1：Source Registry。

只保存机械事实：来源在哪里、已经看到哪里。
不保存 importance / belief / position / relation / memory / subject_level。
"""

from __future__ import annotations

import json
import os
import threading
from datetime import datetime, timezone
from pathlib import Path
from typing import Any


class SourceRegistryError(RuntimeError):
    """Registry 文件损坏或无法写入。"""


def utc_now_iso() -> str:
    return datetime.now(timezone.utc).isoformat()


class SourceRegistry:
    """JSON 文件上的幂等 registry；重跑不复制，不覆盖 raw。"""

    def __init__(self, path: Path | str) -> None:
        self.path = Path(path)
        self._lock = threading.RLock()

    def load(self) -> dict[str, Any]:
        with self._lock:
            if not self.path.exists():
                return {"version": 1, "sources": {}, "sessions": {}}
            try:
                payload = json.loads(self.path.read_text(encoding="utf-8"))
            except (OSError, json.JSONDecodeError) as exc:
                raise SourceRegistryError(f"无法读取 Source Registry：{self.path}") from exc
            if not isinstance(payload, dict):
                raise SourceRegistryError(f"Source Registry 必须是 JSON 对象：{self.path}")
            payload.setdefault("version", 1)
            payload.setdefault("sources", {})
            payload.setdefault("sessions", {})
            return payload

    def save(self, payload: dict[str, Any]) -> None:
        with self._lock:
            self.path.parent.mkdir(parents=True, exist_ok=True)
            temporary_path = self.path.with_suffix(self.path.suffix + ".tmp")
            temporary_path.write_text(
                json.dumps(payload, ensure_ascii=False, indent=2) + "\n",
                encoding="utf-8",
            )
            os.replace(temporary_path, self.path)

    def _mutate(self) -> dict[str, Any]:
        return self.load()

    def upsert_source(
        self,
        *,
        source_id: str,
        source_type: str,
        native_location: str | None = None,
        status: str = "available",
        extra: dict[str, Any] | None = None,
    ) -> dict[str, Any]:
        if not source_id.strip():
            raise ValueError("source_id 不能为空")
        payload = self._mutate()
        sources = payload.setdefault("sources", {})
        existing = sources.get(source_id) or {}
        now = utc_now_iso()
        record: dict[str, Any] = {
            "source_id": source_id,
            "source_type": source_type,
            "status": status,
            "first_seen_at": existing.get("first_seen_at") or now,
            "last_seen_at": now,
        }
        if native_location is not None:
            record["native_location"] = native_location
        elif existing.get("native_location") is not None:
            record["native_location"] = existing["native_location"]
        if extra:
            for key, value in extra.items():
                if value is not None:
                    record[key] = value
        for key in ("cursor",):
            if existing.get(key) is not None and key not in record:
                record[key] = existing[key]
        sources[source_id] = record
        self.save(payload)
        return record

    def get_source(self, source_id: str) -> dict[str, Any] | None:
        sources = self.load().get("sources") or {}
        return sources.get(source_id)

    def list_sources(self) -> list[dict[str, Any]]:
        return list((self.load().get("sources") or {}).values())

    def record_session(
        self,
        *,
        source_id: str,
        native_session_id: str,
        native_ref: str,
        first_seen_at: str | None = None,
        last_seen_at: str | None = None,
        cursor: Any = None,
        metadata: dict[str, Any] | None = None,
    ) -> dict[str, Any]:
        """幂等记录一个 native session；重跑保留 first_seen_at，不覆盖 raw。"""

        if not source_id.strip() or not native_session_id.strip():
            raise ValueError("source_id / native_session_id 不能为空")
        payload = self._mutate()
        sessions = payload.setdefault("sessions", {}).setdefault(source_id, {})
        existing = sessions.get(native_session_id) or {}
        now = utc_now_iso()
        record: dict[str, Any] = {
            "source_id": source_id,
            "native_session_id": native_session_id,
            "native_ref": native_ref,
            "first_seen_at": existing.get("first_seen_at") or first_seen_at or now,
            "last_seen_at": last_seen_at or now,
        }
        if cursor is not None:
            record["cursor"] = cursor
        elif existing.get("cursor") is not None:
            record["cursor"] = existing["cursor"]
        if metadata:
            for key, value in metadata.items():
                if value is not None:
                    record[key] = value
        sessions[native_session_id] = record
        self.save(payload)
        return record

    def get_session(self, source_id: str, native_session_id: str) -> dict[str, Any] | None:
        return (self.load().get("sessions") or {}).get(source_id, {}).get(native_session_id)

    def list_sessions(self, source_id: str) -> list[dict[str, Any]]:
        return list((self.load().get("sessions") or {}).get(source_id, {}).values())

    def update_session_cursor(
        self,
        *,
        source_id: str,
        native_session_id: str,
        cursor: Any,
        last_seen_at: str | None = None,
    ) -> dict[str, Any] | None:
        payload = self._mutate()
        session = payload.setdefault("sessions", {}).setdefault(source_id, {}).get(native_session_id)
        if session is None:
            return None
        session["cursor"] = cursor
        session["last_seen_at"] = last_seen_at or utc_now_iso()
        self.save(payload)
        return session
