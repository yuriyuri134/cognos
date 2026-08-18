"""B5-B8：复用 web/handoff 已实现的 Execution Handoff 能力。

当前模块是薄封装，不重做 E1-E5。
"""

from __future__ import annotations

import sys
from pathlib import Path

WEB_DIR = Path(__file__).resolve().parents[2] / "web"
if str(WEB_DIR) not in sys.path:
    sys.path.insert(0, str(WEB_DIR))

from handoff.dsh_runner import DshRunResult, DshRunnerError, run_envelope
from handoff.envelope import (
    HandoffEnvelopeError,
    build_dsh_prompt,
    build_envelope,
    load_envelope,
    write_envelope,
)
from handoff.exporter import (
    DshExportError,
    build_vault_result,
    export_session,
)

__all__ = [
    "DshExportError",
    "DshRunResult",
    "DshRunnerError",
    "HandoffEnvelopeError",
    "build_dsh_prompt",
    "build_envelope",
    "build_vault_result",
    "export_session",
    "load_envelope",
    "run_envelope",
    "write_envelope",
]
