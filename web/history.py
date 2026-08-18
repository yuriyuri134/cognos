"""第一发生场的追加式原文记录。

这里保存的是可回指的沟通发生，不宣称这些字段就是 Cognos 的最终本体。
"""

from __future__ import annotations

import json
import os
import threading
import uuid
from datetime import datetime, timezone
from pathlib import Path
from typing import Any


class HistoryCorruptionError(RuntimeError):
    """历史文件含有无法读取的行；拒绝静默跳过。"""


class AppendOnlyHistory:
    """以 JSONL 追加消息；读取时保留未来添加的未知字段。"""

    def __init__(self, path: Path) -> None:
        self.path = path
        self._lock = threading.RLock()

    def read_all(self) -> list[dict[str, Any]]:
        with self._lock:
            if not self.path.exists():
                return []

            records: list[dict[str, Any]] = []
            try:
                with self.path.open("r", encoding="utf-8-sig") as stream:
                    for line_number, line in enumerate(stream, start=1):
                        if not line.strip():
                            continue
                        try:
                            record = json.loads(line)
                        except json.JSONDecodeError as exc:
                            raise HistoryCorruptionError(
                                f"历史第 {line_number} 行不是有效 JSON：{self.path}"
                            ) from exc
                        if not isinstance(record, dict):
                            raise HistoryCorruptionError(
                                f"历史第 {line_number} 行不是对象：{self.path}"
                            )
                        records.append(record)
            except OSError as exc:
                raise HistoryCorruptionError(f"无法读取历史：{self.path}") from exc
            return records

    def get_by_id(self, occurrence_id: str) -> dict[str, Any] | None:
        """从历史中寻找指定 id 的发生记录。"""
        all_records = self.read_all()
        for record in reversed(all_records):
            if record.get("id") == occurrence_id:
                return record
        return None

    def append_message(
        self,
        *,
        author: str,
        content: str,
        in_reply_to: str | None = None,
        runtime_ref: dict[str, str] | None = None,
        target: str | None = None,
        author_participant_id: str | None = None,
        **extra_fields: Any,
    ) -> dict[str, Any]:
        """保存原始正文；只用 strip 判断空白，不改写正文。"""

        if not author.strip():
            raise ValueError("author 不能为空")
        if not content.strip():
            raise ValueError("content 不能为空")

        record: dict[str, Any] = {
            "id": str(uuid.uuid4()),
            "occurred_at": datetime.now(timezone.utc).isoformat(),
            "author": author,
            "content": content,
        }
        if in_reply_to:
            record["in_reply_to"] = in_reply_to
        if target:
            record["target"] = target
        if author_participant_id:
            record["author_participant_id"] = author_participant_id
        if runtime_ref:
            record["runtime_ref"] = dict(runtime_ref)
        for k, v in extra_fields.items():
            if v is not None:
                record[k] = v

        serialized = json.dumps(record, ensure_ascii=False, separators=(",", ":"))
        with self._lock:
            self.path.parent.mkdir(parents=True, exist_ok=True)
            try:
                with self.path.open("a", encoding="utf-8", newline="\n") as stream:
                    stream.write(serialized)
                    stream.write("\n")
                    stream.flush()
                    os.fsync(stream.fileno())
            except OSError as exc:
                raise RuntimeError(f"无法追加历史：{self.path}") from exc
        return record
