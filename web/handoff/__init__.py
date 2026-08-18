"""Cognos Execution Handoff E1-E5 薄层。

- envelope.py     : E1 Handoff envelope 与 DSH prompt
- dsh_runner.py   : E2 DSH headless runner（只创建，不 resume）
- exporter.py     : E3 session log / git / test provenance 机械导出
- result_poster.py: E4 把 Result R 追加回第一发生场
"""

from .envelope import build_dsh_prompt, build_envelope, load_envelope, write_envelope

__all__ = [
    "build_dsh_prompt",
    "build_envelope",
    "load_envelope",
    "write_envelope",
]
