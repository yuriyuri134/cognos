"""Cognos Phase 1 — 从原始来源回放一次真实运行。

这不是 Context Builder，也不规定 Cognos 的记忆 schema。它只留下三个事实：
实际送入了什么、原文从哪里来、Codex 的哪一次 thread/turn 承载了回复。
"""

from __future__ import annotations

import argparse
import asyncio
import hashlib
import json
import sys
from datetime import datetime, timezone
from pathlib import Path
from typing import Any


PHASE1_DIR = Path(__file__).resolve().parent
PROJECT_DIR = PHASE1_DIR.parent
PHASE0_DIR = PROJECT_DIR / "phase0"
sys.path.insert(0, str(PHASE0_DIR))

from codex_runtime import CodexRuntimeAdapter, JsonThreadStore


DEFAULT_STATE_PATH = PHASE1_DIR / ".state" / "replay_thread.json"


def read_exact_lines(path: Path, start: int, end: int) -> tuple[str, str]:
    """读取闭区间行号，并返回原文与整份来源的 SHA-256。"""

    if start < 1 or end < start:
        raise ValueError("行号必须满足 1 <= start <= end")

    raw = path.read_bytes()
    lines = raw.decode("utf-8-sig").splitlines(keepends=True)
    if end > len(lines):
        raise ValueError(f"来源只有 {len(lines)} 行，不能读取到第 {end} 行")

    excerpt = "".join(lines[start - 1 : end])
    return excerpt, hashlib.sha256(raw).hexdigest()


def append_jsonl(path: Path, record: dict[str, Any]) -> None:
    """追加探针实录；已有行不会被重写。"""

    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("a", encoding="utf-8", newline="\n") as stream:
        stream.write(json.dumps(record, ensure_ascii=False, separators=(",", ":")))
        stream.write("\n")


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="从指定原文行回放一次 Codex turn，并保存精确来源锚点"
    )
    parser.add_argument("source", type=Path, help="原始来源文件")
    parser.add_argument("--start", type=int, required=True, help="起始行（从 1 开始）")
    parser.add_argument("--end", type=int, required=True, help="结束行（包含）")
    parser.add_argument(
        "--cwd",
        type=Path,
        default=PROJECT_DIR,
        help="Codex 的只读工作目录",
    )
    parser.add_argument(
        "--state",
        type=Path,
        default=DEFAULT_STATE_PATH,
        help="独立回放 thread 的恢复状态",
    )
    parser.add_argument("--record", type=Path, help="可选：追加写入 JSONL 探针实录")
    parser.add_argument("--model", default=None, help="可选 Codex 模型")
    return parser.parse_args()


async def run() -> None:
    args = parse_args()
    source = args.source.resolve()
    excerpt, source_sha256 = read_exact_lines(source, args.start, args.end)

    adapter = CodexRuntimeAdapter(
        cwd=args.cwd,
        state_store=JsonThreadStore(args.state),
        model=args.model,
    )
    reply = await adapter.send(excerpt)

    record = {
        "recorded_at": datetime.now(timezone.utc).isoformat(),
        "runtime": "codex",
        "thread_id": reply.thread_id,
        "turn_id": reply.turn_id,
        "source": {
            "path": str(source),
            "sha256": source_sha256,
            "line_start": args.start,
            "line_end": args.end,
        },
        "input": excerpt,
        "output": reply.text,
    }
    if args.record:
        append_jsonl(args.record.resolve(), record)

    print(json.dumps(record, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    asyncio.run(run())
