from __future__ import annotations

import os
import sys
from pathlib import Path
import unittest

WEB_DIR = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(WEB_DIR))

from runtime_profile import build_runtime_profile


class RuntimeProfileTest(unittest.TestCase):
    def test_fresh_sdk_thread_is_not_claimed_as_complete_model_input(self):
        class SdkAdapter:
            activity_input_scope = "fresh_thread_runtime_context_not_captured"
            async def send_activity(self, message, *, on_request):
                pass
        profile = build_runtime_profile(SdkAdapter())
        self.assertEqual("fresh_thread_runtime_context_not_captured", profile["activity_input_scope"])

    def test_profile_contains_binding_metadata_without_key(self):
        class DeepSeekRuntimeAdapter:
            model = "gpt-5.6"
            model_name = "GPT 5.6 high"
            base_url = "https://relay.example/v1?key=secret"
            api_key = "secret"

        old = os.environ.get("DEEPSEEK_REASONING_EFFORT")
        os.environ["DEEPSEEK_REASONING_EFFORT"] = "high"
        try:
            profile = build_runtime_profile(DeepSeekRuntimeAdapter(), display_name="GPT 5.6 high")
        finally:
            if old is None:
                os.environ.pop("DEEPSEEK_REASONING_EFFORT", None)
            else:
                os.environ["DEEPSEEK_REASONING_EFFORT"] = old
        self.assertEqual("gpt-5.6", profile["model"])
        self.assertEqual("high", profile["reasoning_effort"])
        self.assertEqual("configured", profile["connection"])
        self.assertEqual("https://relay.example/v1", profile["endpoint"])
        self.assertNotIn("api_key", profile)

    def test_profile_separates_wire_provider_from_legacy_adapter_name(self):
        class LegacyAdapter:
            provider = "openai-compatible"
            model = "gpt-5.6"
            base_url = "https://relay.example/v1"
            api_key = "secret"

        profile = build_runtime_profile(LegacyAdapter())
        self.assertEqual("openai-compatible", profile["provider"])
        self.assertEqual("LegacyAdapter", profile["adapter"])
