"""B8 Result 回第一发生场。

薄封装：HTTP posting 实现在 web/handoff/result_poster.py；
Result 追加只通过第一发生场现有 append-only history，不改写旧行。
"""

from __future__ import annotations

import sys
from pathlib import Path

WEB_DIR = Path(__file__).resolve().parents[2] / "web"
if str(WEB_DIR) not in sys.path:
    sys.path.insert(0, str(WEB_DIR))

from handoff.result_poster import (
    build_request_payload,
    format_content_from_vault,
    post_result,
)

__all__ = ["build_request_payload", "format_content_from_vault", "post_result"]
