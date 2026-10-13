"""安全地把运行时失败映射为 API 可见错误。"""

from __future__ import annotations

from codex_runtime import RuntimeUnavailableError


def runtime_api_detail(prefix: str, exc: Exception) -> str:
    """不把上游 URL、响应体、凭据或本机路径直接回显给客户端。"""
    if isinstance(exc, RuntimeUnavailableError):
        return f"{prefix}运行时暂不可用；原始发生已保留。"
    return f"{prefix}处理失败；原始发生已保留。"
