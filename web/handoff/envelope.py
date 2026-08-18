"""E1：最小 Handoff envelope。

Envelope 只是机械投影缓存，不是真相源。
真相源仍然是 Vault 中追加式 JSONL 的原始 occurrence 行。
"""

from __future__ import annotations

import json
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

ENVELOPE_SCHEMA = "cognos.handoff.envelope.v1"
PROJECTION_KIND = "projection/cache"
TRUTH_SOURCE_LABEL = "第一发生场.jsonl"


class HandoffEnvelopeError(RuntimeError):
    """Handoff envelope 缺失、损坏或字段不符合最小约定。"""


def utc_now_iso() -> str:
    return datetime.now(timezone.utc).isoformat()


def projection_notice() -> str:
    return (
        "以下 occurrence 是给 DSH 的临时输入投影（projection/cache），"
        "不是真相源；真相源是 Vault 第一发生场.jsonl 中不可改写的原始行。"
    )


def build_envelope(
    *,
    handoff_id: str,
    created_at: str,
    working_directory: str,
    instruction: str,
    occurrence_refs: list[str],
    source_refs: list[str],
    occurrence_records: list[dict[str, Any]],
    executor_hint: str = "dsh-headless",
    envelope_path: str | None = None,
) -> dict[str, Any]:
    """生成最小 envelope；所有字段机械来自现有 history，不做模型总结。"""

    projected: list[dict[str, Any]] = []
    for record in occurrence_records:
        projected.append(
            {
                "kind": PROJECTION_KIND,
                "truth_source": TRUTH_SOURCE_LABEL,
                "provenance": {
                    "occurrence_id": record.get("id"),
                    "materialized_at": utc_now_iso(),
                },
                "occurrence": record,
            }
        )

    envelope: dict[str, Any] = {
        "schema": ENVELOPE_SCHEMA,
        "handoff_id": handoff_id,
        "created_at": created_at,
        "working_directory": str(working_directory),
        "instruction": instruction,
        "occurrence_refs": list(occurrence_refs),
        "source_refs": list(source_refs),
        "executor": {"kind": executor_hint, "hint": executor_hint},
        "occurrence_projection": {
            "notice": projection_notice(),
            "truth_source": TRUTH_SOURCE_LABEL,
            "occurrences": projected,
        },
    }
    if envelope_path is not None:
        envelope["envelope_path"] = str(envelope_path)
    return envelope


def write_envelope(envelope: dict[str, Any], path: Path) -> Path:
    path = path.expanduser().resolve()
    path.parent.mkdir(parents=True, exist_ok=True)
    temporary_path = path.with_suffix(path.suffix + ".tmp")
    payload = json.dumps(envelope, ensure_ascii=False, indent=2)
    temporary_path.write_text(payload + "\n", encoding="utf-8")
    temporary_path.replace(path)
    return path


def load_envelope(path: Path | str) -> dict[str, Any]:
    path = Path(path)
    try:
        envelope = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        raise HandoffEnvelopeError(f"无法读取 handoff envelope：{path}") from exc

    required = {
        "handoff_id",
        "created_at",
        "working_directory",
        "instruction",
        "occurrence_refs",
        "source_refs",
        "executor",
    }
    if not isinstance(envelope, dict):
        raise HandoffEnvelopeError(f"handoff envelope 必须是 JSON 对象：{path}")
    missing = sorted(required - set(envelope))
    if missing:
        raise HandoffEnvelopeError(f"handoff envelope 缺少字段 {missing}：{path}")

    executor = envelope.get("executor")
    executor_name = (
        executor.get("kind") or executor.get("hint")
        if isinstance(executor, dict)
        else None
    )
    if executor_name != "dsh-headless":
        raise HandoffEnvelopeError(
            f"handoff envelope 的 executor.kind 必须为 dsh-headless：{path}"
        )

    for field in ("occurrence_refs", "source_refs"):
        if not isinstance(envelope[field], list):
            raise HandoffEnvelopeError(f"handoff envelope 字段 {field} 必须是数组：{path}")
    return envelope


def build_dsh_prompt(envelope: dict[str, Any]) -> str:
    """把 envelope 机械拼装成 dsh headless 的一次任务参数。

    刻意不把 projection 包装成“历史真相”，也不要求模型自证成功。
    """

    envelope = load_envelope(envelope) if isinstance(envelope, (str, Path)) else envelope
    projection = envelope.get("occurrence_projection") or {}
    occurrences = projection.get("occurrences") or []

    lines: list[str] = []
    lines.append("Cognos Execution Handoff (E1)")
    lines.append(f"handoff_id: {envelope['handoff_id']}")
    lines.append(f"created_at: {envelope['created_at']}")
    lines.append(f"working_directory: {envelope['working_directory']}")
    executor = envelope.get("executor", {}) or {}
    lines.append(f"executor.kind: {executor.get('kind') or executor.get('hint')}")
    lines.append(f"occurrence_refs: {json.dumps(envelope.get('occurrence_refs', []), ensure_ascii=False)}")
    lines.append(f"source_refs: {json.dumps(envelope.get('source_refs', []), ensure_ascii=False)}")
    lines.append("")
    lines.append("[provenance]")
    lines.append(f"truth_source: {projection.get('truth_source', TRUTH_SOURCE_LABEL)}")
    lines.append(f"kind: {PROJECTION_KIND}")
    lines.append("warning: 下面引用是投影缓存，不是真相源；不要把它当成历史去改写。")
    if envelope.get("envelope_path"):
        lines.append(f"envelope_path: {envelope['envelope_path']}")
    lines.append("")
    lines.append("[instruction]")
    lines.append(envelope["instruction"])
    lines.append("")

    if occurrences:
        lines.append("[occurrence projection]")
        for index, item in enumerate(occurrences, start=1):
            record = item.get("occurrence", {})
            lines.append(f"--- occurrence {index} / occurrence_id={record.get('id')} ---")
            lines.append(f"author: {record.get('author', '未知')}")
            lines.append(f"occurred_at: {record.get('occurred_at', '')}")
            lines.append(f"content: {record.get('content', '')}")
            lines.append("")

    lines.append("[execution rules]")
    lines.append("1. 只允许在 working_directory 内创建或修改文件。")
    lines.append("2. 不要修改或删除 web/.state/ 下的 gemini_thread.json / gemini_b_thread.json / codex_thread.json。")
    lines.append("3. 不要读取、打印或提交 web/.env、API key、authorization、credentials。")
    lines.append("4. 如果任务要求改代码，必须实际运行测试，并报告真实命令和真实输出位置。")
    lines.append("5. 不要把“模型声称成功”当作测试通过；执行结果是唯一的可观察依据。")
    lines.append("")
    lines.append("请执行 instruction；最后返回你实际运行过的命令、测试输出摘要，以及修改的文件清单。")
    return "\n".join(lines)
