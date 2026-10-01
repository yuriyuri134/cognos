"""Public, non-secret runtime metadata for the organization UI and traces."""

from __future__ import annotations

import os
from typing import Any
from urllib.parse import urlsplit, urlunsplit


def _public_endpoint(value: Any) -> str | None:
    if not isinstance(value, str) or not value:
        return None
    try:
        parsed = urlsplit(value)
        if parsed.scheme and parsed.netloc:
            return urlunsplit((parsed.scheme, parsed.netloc, parsed.path, "", ""))
    except ValueError:
        return None
    return value.split("?", 1)[0]


def build_runtime_profile(runtime: Any, *, display_name: str = "") -> dict[str, Any]:
    """Describe the binding without exposing credentials or conversation state."""
    model = getattr(runtime, "model", None) or display_name
    if getattr(runtime, "model_identity_optional", False):
        model = getattr(runtime, "model", None)
    endpoint = _public_endpoint(getattr(runtime, "base_url", None) or getattr(runtime, "endpoint", None))
    effort = getattr(runtime, "reasoning_effort", None)
    if not effort and runtime.__class__.__name__.lower().startswith("deepseek"):
        effort = os.environ.get("DEEPSEEK_REASONING_EFFORT", "").strip() or None
    capabilities = getattr(runtime, "capabilities", None)
    if not isinstance(capabilities, list):
        capabilities = ["conversation"]
    return {
        "provider": getattr(runtime, "provider", None) or runtime.__class__.__name__,
        "adapter": runtime.__class__.__name__,
        "turn_ref_kind": getattr(runtime, "turn_ref_kind", None),
        "endpoint": endpoint,
        "model": model,
        "reasoning_effort": effort,
        "capabilities": capabilities,
        "activity_input_scope": (getattr(runtime, "activity_input_scope", "assembled_activity_only")
                                 if callable(getattr(runtime, "send_activity", None))
                                 else "runtime_managed_history_not_captured"),
        "fallback_policy": getattr(runtime, "fallback_policy", None),
        "display_name": display_name or getattr(runtime, "model_name", None) or model,
        "connection": "configured" if getattr(runtime, "api_key", None) else "local_or_managed",
    }
