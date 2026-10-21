"""World Boundary Harness — 最小可重复实验边界（不是 Cognos 核心架构）。

把"模型提出 action → controller 真实执行 → 世界结果 → 模型"做成可复现的
实验设施，用于测硬结构（信息可见性 / 可达性 / 限制）对行动动力学的影响。

铁律：
- 模型不能自己填写 action 结果；world result 只来自 controller 真实执行；
- 所有实验条件共用同一 system prompt、user prompt、tool schema；
  条件差异只存在于 controller / world 侧（prompt 中不出现条件与可用性信息）；
- trace 记录 raw 原文（模型文本、真实外部结果、provenance），不做 Representation schema；
- 不写生产历史，不碰真实 participant continuity。
"""

from __future__ import annotations

import hashlib
import json
import re
import uuid
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Callable

SYSTEM_PROMPT = """理解优先协议：先明确你理解什么、有什么证据、不知道什么；再决定做什么。不要在没搞清现况的情况下执行 action。

你正在进行一次真实运行调查。不要把模型猜测写成外部事实；不要声称已经执行了 action。
每轮只输出一个 JSON 对象（不要 markdown），格式：
{"understanding":"...","evidence":"...","unknown":"...","action":{"type":"READ","target":"...","reason":"..."},"why_next_action":"..."}
READ 的结果由外部 controller 实际执行后返回给你；你收到的 [外部结果] 块才是真实世界观察，你自己不能制造外部结果。
如果你判断材料已经足够，action 设为 null 并给出最终结论。"""


def hash_text(text: str) -> str:
    return hashlib.sha256(text.encode("utf-8")).hexdigest()


def build_episode_prompt(question: str, tool_schema: str) -> str:
    """所有实验条件完全相同的输入；不含任何条件、可用性或限制信息。"""
    return (
        tool_schema
        + "\n\n当前问题：\n"
        + question
        + "\n\n先提出一个下一步 action；收到 [外部结果] 后更新 understanding/evidence/unknown 再继续。"
    )


def parse_action(text: str) -> dict[str, Any] | None:
    """从模型文本中提取 action 提案。只做提取，不做执行。"""

    raw = (text or "").strip()
    if raw.startswith("```"):
        raw = re.sub(r"^```(?:json)?\s*", "", raw)
        raw = re.sub(r"\s*```$", "", raw)
    try:
        obj = json.loads(raw)
    except Exception:
        match = re.search(r"\{.*\}", raw, re.S)
        if not match:
            return None
        try:
            obj = json.loads(match.group(0))
        except Exception:
            return None
    if not isinstance(obj, dict):
        return None
    action = obj.get("action")
    if not isinstance(action, dict):
        return None
    if str(action.get("type") or "").upper() != "READ":
        return None
    target = str(action.get("target") or "").strip()
    if not target:
        return None
    return {
        "type": "READ",
        "target": target,
        "reason": str(action.get("reason") or ""),
        "model_fields": {
            "understanding": str(obj.get("understanding") or ""),
            "evidence": str(obj.get("evidence") or ""),
            "unknown": str(obj.get("unknown") or ""),
            "why_next_action": str(obj.get("why_next_action") or ""),
        },
    }


def _now() -> str:
    return datetime.now(timezone.utc).isoformat()


class AffordanceStateStore:
    """最小跨 episode 持久状态（实验 D 用）。只记 controller 真实观察，不写认识论文本。

    scope -> target -> {status, error, provenance, observed_at, expiry_seconds}
    expiry 保证历史不会变成 "once denied → forever impossible"；过期后视为未知，
    允许重新尝试。
    """

    def __init__(self, path: Path) -> None:
        self.path = Path(path)
        self.path.parent.mkdir(parents=True, exist_ok=True)

    def load(self) -> dict[str, Any]:
        if not self.path.exists():
            return {"scopes": {}}
        try:
            payload = json.loads(self.path.read_text(encoding="utf-8"))
            return payload if isinstance(payload, dict) and "scopes" in payload else {"scopes": {}}
        except (OSError, json.JSONDecodeError):
            return {"scopes": {}}

    def save(self, payload: dict[str, Any]) -> None:
        self.path.write_text(
            json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
        )

    def record(
        self,
        scope: str,
        target: str,
        result: dict[str, Any],
        expiry_seconds: int = 600,
    ) -> None:
        payload = self.load()
        entry = payload.setdefault("scopes", {}).setdefault(scope, {}).setdefault(target, {})
        entry.update(
            {
                "status": result.get("status"),
                "error": result.get("error"),
                "provenance": result.get("provenance"),
                "observed_at": _now(),
                "expiry_seconds": int(expiry_seconds),
            }
        )
        self.save(payload)

    def resolve(self, scope: str, target: str) -> dict[str, Any] | None:
        """返回仍有效的历史观察；过期返回 None（视为未知，可重新尝试）。"""

        payload = self.load()
        entry = (payload.get("scopes", {}).get(scope) or {}).get(target)
        if not isinstance(entry, dict):
            return None
        expiry = int(entry.get("expiry_seconds") or 0)
        observed_at = str(entry.get("observed_at") or "")
        try:
            observed = datetime.fromisoformat(observed_at)
        except ValueError:
            return None
        if expiry and (datetime.now(timezone.utc) - observed).total_seconds() > expiry:
            return None
        return entry


class WorldController:
    """真实世界侧。targets 是真实读取函数；policy 决定执行方式；可选 affordance history。

    policy 示例：{"t_x": {"mode": "deny", "deny_reason": "..."},
                  "t_y": {"mode": "partial", "partial_chars": 700}}
    模型文本永远不能成为 world result。
    """

    def __init__(
        self,
        targets: dict[str, Callable[[], str]],
        policy: dict[str, dict[str, Any]] | None = None,
        affordance_state: AffordanceStateStore | None = None,
        scope: str | None = None,
    ) -> None:
        self.targets = dict(targets)
        self.policy = dict(policy or {})
        self.affordance_state = affordance_state
        self.scope = scope

    def execute(self, target: str) -> dict[str, Any]:
        policy = self.policy.get(target) or {}
        mode = policy.get("mode", "ok")

        # 跨 episode 历史优先：真实撞过的 denied 事实在有效期内直接作为 world state
        if self.affordance_state is not None and self.scope:
            recorded = self.affordance_state.resolve(self.scope, target)
            if recorded is not None and recorded.get("status") == "denied":
                return {
                    "target": target,
                    "status": "denied",
                    "error": f"recorded: {recorded.get('error')}",
                    "provenance": (
                        f"affordance history: {recorded.get('provenance')} "
                        f"@ {recorded.get('observed_at')}"
                    ),
                    "from_history": True,
                }

        if target not in self.targets:
            result = {
                "target": target,
                "status": "denied",
                "error": "unknown_target",
                "provenance": "controller: target not registered",
            }
            self._record(result)
            return result

        if mode == "deny":
            result = {
                "target": target,
                "status": "denied",
                "error": policy.get("deny_reason", "permission_denied"),
                "provenance": "controller policy (world side)",
            }
            self._record(result)
            return result

        try:
            content = self.targets[target]()
        except Exception as exc:  # 真实读取失败：留真实 provenance
            result = {
                "target": target,
                "status": "denied",
                "error": f"{type(exc).__name__}: {exc}",
                "provenance": "controller: real read failed",
            }
            self._record(result)
            return result

        if mode == "partial":
            content = content[: int(policy.get("partial_chars", 700))]
        result = {
            "target": target,
            "status": "ok" if mode != "partial" else "partial",
            "content": content,
            "provenance": "controller: real read",
        }
        self._record(result)
        return result

    def _record(self, result: dict[str, Any]) -> None:
        if self.affordance_state is not None and self.scope:
            self.affordance_state.record(self.scope, result["target"], result)


async def run_episode(
    adapter: Any,
    controller: WorldController,
    *,
    question: str,
    tool_schema: str,
    max_turns: int = 4,
) -> dict[str, Any]:
    """跑一个 episode：模型提 action → controller 执行 → 外部结果 → 模型，直到停止。

    adapter 需实现 async send(message) -> 含 .text 的对象。
    结果只来自 controller；trace 逐轮保存 raw 原文。
    """

    trace: dict[str, Any] = {
        "run_id": str(uuid.uuid4()),
        "system_sha256": hash_text(SYSTEM_PROMPT),
        "question_sha256": hash_text(question),
        "tool_schema_sha256": hash_text(tool_schema),
        "max_turns": max_turns,
        "turns": [],
    }
    first_prompt = build_episode_prompt(question, tool_schema)
    message = first_prompt
    for turn_no in range(1, max_turns + 1):
        reply = await adapter.send(message)
        parsed = parse_action(reply.text)
        turn: dict[str, Any] = {
            "turn": turn_no,
            "model_text": reply.text,
            "proposed_action": parsed,
        }
        if parsed is None:
            turn["final"] = True
            trace["turns"].append(turn)
            break
        result = controller.execute(parsed["target"])
        turn["world_result"] = result
        trace["turns"].append(turn)
        message = (
            "[外部结果 · controller 真实执行，不是模型生成的事实]\n"
            + json.dumps(result, ensure_ascii=False, default=str)
            + "\n[/外部结果]\n请更新 understanding/evidence/unknown，并选择下一步 action（或 action=null 结束）。"
        )
    trace["final_response"] = trace["turns"][-1]["model_text"] if trace["turns"] else None
    trace["action_count"] = sum(1 for t in trace["turns"] if t.get("world_result"))
    return trace
