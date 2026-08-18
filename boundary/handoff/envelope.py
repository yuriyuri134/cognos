"""薄封装：B5 Handoff envelope 实现在 web/handoff/envelope.py。"""

from boundary.handoff import (
    HandoffEnvelopeError,
    build_dsh_prompt,
    build_envelope,
    load_envelope,
    write_envelope,
)

__all__ = [
    "HandoffEnvelopeError",
    "build_dsh_prompt",
    "build_envelope",
    "load_envelope",
    "write_envelope",
]
