"""Packet B: open activity envelope with backwards compatibility."""

from __future__ import annotations

import sys
from pathlib import Path
import unittest

WEB_DIR = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(WEB_DIR))

from activity_service import parse_activity_reply


class ActivityEnvelopeTest(unittest.TestCase):
    def test_message_only_and_pause_only_are_valid(self):
        self.assertEqual("先停一下", parse_activity_reply('{"message":"先停一下"}')['message'])
        parsed = parse_activity_reply('{"pause":{"reason":"材料还不够"}}')
        self.assertEqual("材料还不够", parsed["pause"]["reason"])
        self.assertIsNone(parsed["move"])

    def test_move_without_operations_and_multiple_formations_are_preserved(self):
        parsed = parse_activity_reply(
            '{"message":"先并置看看", "formation_updates":['
            '{"text":"读法 A"},{"text":"读法 B"}], '
            '"move":{"text":"把两段材料放在一起","refs":["a@1"],'
            '"formation_refs":["f-1"],"tension_refs":["t-1"]}}')
        self.assertEqual(2, len(parsed["formation_updates"]))
        self.assertEqual("把两段材料放在一起", parsed["move"]["text"])
        self.assertEqual([], parsed["move"]["operations"])
        self.assertEqual(["f-1"], parsed["move"]["formation_refs"])
        self.assertEqual(["t-1"], parsed["move"]["tension_refs"])

    def test_tension_updates_are_optional_and_keep_status(self):
        parsed = parse_activity_reply(
            '{"message":"这里还没有安顿", "tension_updates":['
            '{"text":"收入问题和自主性问题还没有分开","status":"open"},'
            '{"text":"旧张力已被重新理解","status":"changed"}]}'
        )
        self.assertEqual(2, len(parsed["tension_updates"]))
        self.assertEqual("open", parsed["tension_updates"][0]["status"])
        self.assertEqual("changed", parsed["tension_updates"][1]["status"])

    def test_tension_revision_preserves_omitted_fields(self):
        resolved = parse_activity_reply(
            '{"tension_updates":[{"id":"t1","status":"resolved"}]}'
        )["tension_updates"][0]
        self.assertEqual({"id": "t1", "status": "resolved"}, resolved)

        text_only = parse_activity_reply(
            '{"tension_updates":[{"id":"t1","text":"后来把措辞改清楚"}]}'
        )["tension_updates"][0]
        self.assertEqual({"id": "t1", "text": "后来把措辞改清楚"}, text_only)

    def test_fenced_json_and_description_aliases_are_preserved(self):
        parsed = parse_activity_reply("""```json
        {"message":"继续靠近","formation_updates":[{"id":"f-1","description":"一个暂定读法","status":"forming"}],
         "tension_updates":[{"id":"t-1","description":"两个方向还没有分开","status":"open"}]}
        ```""")
        self.assertTrue(parsed["transport_wrapped"])
        self.assertEqual("一个暂定读法", parsed["formation_updates"][0]["text"])
        self.assertEqual("f-1", parsed["formation_updates"][0]["id"])
        self.assertEqual("两个方向还没有分开", parsed["tension_updates"][0]["text"])
        self.assertEqual("t-1", parsed["tension_updates"][0]["id"])

    def test_common_markdown_transport_repairs_are_narrow_and_recorded(self):
        raw = (
            '{"formation\\_updates":\\[{"text":"暂定读法","status":"active"}\\],'
            '"tension\\_updates":\\[{"text":"仍未安顿","status":"active"}\\],'
            '"pause":{"text":"先停在这里"}}'
        )
        parsed = parse_activity_reply(raw)
        self.assertEqual("暂定读法", parsed["formation_updates"][0]["text"])
        self.assertEqual("open", parsed["tension_updates"][0]["status"])
        self.assertEqual("先停在这里", parsed["pause"]["reason"])
        self.assertEqual(
            ["markdown_escaped_underscore", "markdown_escaped_square_brackets",
             "tension_status_active_to_open", "pause_text_to_reason"],
            parsed["transport_repairs"],
        )
        self.assertEqual(raw, parsed["raw_text"])

    def test_legacy_response_is_normalized(self):
        parsed = parse_activity_reply(
            '{"answer":"继续", "situation":{"text":"暂定理解"}, '
            '"commands":[{"type":"read","document":"a"}]}')
        self.assertTrue(parsed["legacy"])
        self.assertEqual("继续", parsed["message"])
        self.assertEqual("暂定理解", parsed["formation_updates"][0]["text"])
        self.assertEqual("read", parsed["move"]["operations"][0]["type"])

    def test_empty_object_is_rejected(self):
        with self.assertRaises(ValueError):
            parse_activity_reply('{}')
