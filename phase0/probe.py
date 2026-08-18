"""Cognos Phase 0 — 从命令行执行一次真实 Codex 往返。"""

from __future__ import annotations

import argparse
import asyncio
import json
from pathlib import Path

from codex_runtime import CodexRuntimeAdapter, JsonThreadStore


PHASE0_DIR = Path(__file__).resolve().parent
PROJECT_DIR = PHASE0_DIR.parent
DEFAULT_STATE_PATH = PHASE0_DIR / ".state" / "codex_thread.json"


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="验证 Cognos 能否创建并恢复 Codex thread")
    parser.add_argument("message", help="发给 Codex 的测试消息")
    parser.add_argument(
        "--cwd",
        type=Path,
        default=PROJECT_DIR,
        help="Codex 的只读工作目录；默认是 Cognos 项目目录",
    )
    parser.add_argument(
        "--state",
        type=Path,
        default=DEFAULT_STATE_PATH,
        help="保存 thread_id 的本地 JSON 文件",
    )
    parser.add_argument("--model", default=None, help="可选；不指定时使用 Codex 当前默认模型")
    return parser.parse_args()


async def run() -> None:
    args = parse_args()
    adapter = CodexRuntimeAdapter(
        cwd=args.cwd,
        state_store=JsonThreadStore(args.state),
        model=args.model,
    )
    reply = await adapter.send(args.message)
    print(
        json.dumps(
            {
                "thread_id": reply.thread_id,
                "turn_id": reply.turn_id,
                "final_response": reply.text,
            },
            ensure_ascii=False,
            indent=2,
        )
    )


if __name__ == "__main__":
    asyncio.run(run())
