"""薄封装：B8 Result poster 实现在 web/handoff/result_poster.py。"""

from boundary.result import build_request_payload, format_content_from_vault, post_result

__all__ = ["build_request_payload", "format_content_from_vault", "post_result"]
