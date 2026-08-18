"""启动标准 ChatGPT 浏览器登录，并通过本地状态文件报告进度。"""

from __future__ import annotations

import argparse
import asyncio
import json
import os
from pathlib import Path

from openai_codex import AsyncCodex


DEFAULT_STATUS_PATH = Path(__file__).resolve().parent / ".state" / "login_browser.json"


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="启动 Codex ChatGPT 浏览器登录")
    parser.add_argument("--status-file", type=Path, default=DEFAULT_STATUS_PATH)
    return parser.parse_args()


def write_status(path: Path, payload: dict) -> None:
    """原子替换状态；文件中绝不保存 token。"""

    path.parent.mkdir(parents=True, exist_ok=True)
    temporary_path = path.with_suffix(path.suffix + ".tmp")
    temporary_path.write_text(
        json.dumps(payload, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )
    os.replace(temporary_path, path)


async def run() -> None:
    args = parse_args()
    try:
        async with AsyncCodex() as codex:
            handle = await codex.login_chatgpt()
            write_status(
                args.status_file,
                {"status": "awaiting_user", "auth_url": handle.auth_url},
            )
            result = await handle.wait()
            write_status(
                args.status_file,
                {
                    "status": "succeeded" if result.success else "failed",
                    "success": result.success,
                    "error": result.error,
                },
            )
    except Exception as exc:
        write_status(
            args.status_file,
            {"status": "failed", "success": False, "error": str(exc)},
        )
        raise


if __name__ == "__main__":
    asyncio.run(run())

