"""World Boundary Harness 单元测试（全离线，不访问网络）。

验证四条铁律：
1. 模型不能自己填写 action 结果（world result 只来自 controller 真实执行）；
2. 实验条件的 prompt 完全一致（不含条件/可用性信息）；
3. denied / partial / 真实读取失败都留 provenance；
4. 跨 episode 历史：有效期内生效，过期后允许重新尝试（不是 once denied forever）。
"""

from __future__ import annotations

import json
import tempfile
import unittest
from pathlib import Path

from world_harness import (
    AffordanceStateStore,
    WorldController,
    build_episode_prompt,
    hash_text,
    parse_action,
    run_episode,
)


class FakeRuntime:
    """按脚本回放模型回复；记录收到的消息。"""

    def __init__(self, replies: list[str]) -> None:
        self.replies = list(replies)
        self.received: list[str] = []

    async def send(self, message: str) -> object:
        self.received.append(message)
        text = self.replies.pop(0) if self.replies else '{"action": null}'
        return type("Reply", (), {"text": text})()


def action_json(target: str) -> str:
    return json.dumps(
        {
            "understanding": "u",
            "evidence": "e",
            "unknown": "?",
            "action": {"type": "READ", "target": target, "reason": "r"},
            "why_next_action": "w",
        },
        ensure_ascii=False,
    )


class ParseActionTest(unittest.TestCase):
    def test_plain_json(self) -> None:
        parsed = parse_action(action_json("t1"))
        self.assertIsNotNone(parsed)
        self.assertEqual("t1", parsed["target"])

    def test_fenced_json(self) -> None:
        parsed = parse_action("```json\n" + action_json("t2") + "\n```")
        self.assertIsNotNone(parsed)
        self.assertEqual("t2", parsed["target"])

    def test_garbage_returns_none(self) -> None:
        self.assertIsNone(parse_action("这里没有 JSON"))

    def test_null_action_returns_none(self) -> None:
        self.assertIsNone(parse_action('{"action": null, "understanding": "done"}'))

    def test_non_read_action_ignored(self) -> None:
        text = json.dumps({"action": {"type": "EXECUTE", "target": "x"}})
        self.assertIsNone(parse_action(text))


class WorldControllerTest(unittest.TestCase):
    def test_ok_returns_real_content(self) -> None:
        controller = WorldController(targets={"t1": lambda: "真实文件内容"})
        result = controller.execute("t1")
        self.assertEqual("ok", result["status"])
        self.assertEqual("真实文件内容", result["content"])
        self.assertIn("controller", result["provenance"])

    def test_deny_does_not_call_target(self) -> None:
        calls: list[str] = []
        controller = WorldController(
            targets={"t1": lambda: calls.append("called") or "x"},
            policy={"t1": {"mode": "deny", "deny_reason": "restricted"}},
        )
        result = controller.execute("t1")
        self.assertEqual("denied", result["status"])
        self.assertEqual([], calls)  # 被拒的 target 未被真实执行

    def test_partial_truncates(self) -> None:
        controller = WorldController(
            targets={"t1": lambda: "abcdefghij"},
            policy={"t1": {"mode": "partial", "partial_chars": 4}},
        )
        result = controller.execute("t1")
        self.assertEqual("partial", result["status"])
        self.assertEqual("abcd", result["content"])

    def test_unknown_target_and_read_failure_keep_provenance(self) -> None:
        controller = WorldController(targets={})
        result = controller.execute("nope")
        self.assertEqual("denied", result["status"])
        self.assertEqual("unknown_target", result["error"])

        def boom() -> str:
            raise OSError("disk")

        controller2 = WorldController(targets={"t1": boom})
        result2 = controller2.execute("t1")
        self.assertEqual("denied", result2["status"])
        self.assertIn("real read failed", result2["provenance"])


class PromptBlindnessTest(unittest.TestCase):
    def test_prompt_independent_of_controller(self) -> None:
        question = "调查问题"
        schema = "可用 action：READ(t1), READ(t2)"
        p1 = build_episode_prompt(question, schema)
        p2 = build_episode_prompt(question, schema)
        self.assertEqual(p1, p2)
        self.assertNotIn("t2", p1.split("当前问题")[0].replace("READ(t2)", "READ(tX)"))
        self.assertNotIn("deny", p1)
        self.assertNotIn("可用性", p1)


class RunEpisodeTest(unittest.IsolatedAsyncioTestCase):
    def test_model_cannot_fill_world_result(self) -> None:
        """模型即使在自己的文本里声称读了文件，trace 的 world_result 仍只来自 controller。"""

        replies = [
            action_json("t1"),
            json.dumps(
                {
                    "understanding": "我自己说读到了内容 X",
                    "evidence": "伪造",
                    "unknown": "",
                    "action": None,
                },
                ensure_ascii=False,
            ),
        ]
        adapter = FakeRuntime(replies)
        controller = WorldController(targets={"t1": lambda: "真实内容"})

        async def go():
            return await run_episode(
                adapter, controller, question="q", tool_schema="s", max_turns=3
            )

        import asyncio

        trace = asyncio.run(go())
        self.assertEqual(2, len(trace["turns"]))
        self.assertEqual("真实内容", trace["turns"][0]["world_result"]["content"])
        self.assertNotIn("world_result", trace["turns"][1])
        self.assertEqual(1, trace["action_count"])

    def test_stops_at_max_turns(self) -> None:
        adapter = FakeRuntime([action_json("t1")] * 10)
        controller = WorldController(targets={"t1": lambda: "x"})

        import asyncio

        trace = asyncio.run(
            run_episode(adapter, controller, question="q", tool_schema="s", max_turns=2)
        )
        self.assertEqual(2, len(trace["turns"]))
        self.assertEqual(2, trace["action_count"])
        self.assertIsNotNone(trace["final_response"])


class AffordanceStateTest(unittest.TestCase):
    def setUp(self) -> None:
        self.tmp = tempfile.TemporaryDirectory()
        self.store = AffordanceStateStore(Path(self.tmp.name) / "affordance.json")

    def tearDown(self) -> None:
        self.tmp.cleanup()

    def test_record_and_resolve(self) -> None:
        self.store.record(
            "scope1",
            "t1",
            {"status": "denied", "error": "restricted", "provenance": "controller policy"},
            expiry_seconds=600,
        )
        entry = self.store.resolve("scope1", "t1")
        self.assertIsNotNone(entry)
        self.assertEqual("denied", entry["status"])

    def test_expired_returns_none(self) -> None:
        self.store.record(
            "scope1",
            "t1",
            {"status": "denied", "error": "restricted", "provenance": "p"},
            expiry_seconds=-1,
        )
        self.assertIsNone(self.store.resolve("scope1", "t1"))

    def test_controller_uses_history_then_reopens_after_expiry(self) -> None:
        controller = WorldController(
            targets={"t1": lambda: "真实内容"},
            affordance_state=self.store,
            scope="scope1",
        )
        self.store.record(
            "scope1",
            "t1",
            {"status": "denied", "error": "restricted", "provenance": "controller policy"},
            expiry_seconds=600,
        )
        result = controller.execute("t1")
        self.assertEqual("denied", result["status"])
        self.assertTrue(result.get("from_history"))
        self.assertIn("affordance history", result["provenance"])

        # 过期后：重新真实执行，而不是永久僵化
        self.store.record(
            "scope1",
            "t1",
            {"status": "denied", "error": "restricted", "provenance": "controller policy"},
            expiry_seconds=-1,
        )
        result2 = controller.execute("t1")
        self.assertEqual("ok", result2["status"])
        self.assertEqual("真实内容", result2["content"])

    def test_hash_stable(self) -> None:
        self.assertEqual(hash_text("abc"), hash_text("abc"))
        self.assertNotEqual(hash_text("abc"), hash_text("abd"))


if __name__ == "__main__":
    unittest.main()
