"""Experiment D — Cross-Episode Plasticity（真实调用，输出在 web/.state/experiments/）。

Episode 1（先跑）：调查 occurrence 12d26518 信封截断问题；world 侧真实拒绝
READ(t_deepseek_thread)，controller 把拒绝记入持久 AffordanceState
（scope=deepseek_reply_integrity）。trace 与语义笔记落盘供后续条件使用。

Episode 2（全新 continuity、新问题：occurrence 28f72720 与 thread state 的
一致性核验）：
  D0 无历史（baseline，全可读）；
  D1 语义承接：episode 1 的拒绝事实机械提取为普通文字放进 question；
  D2 结构承接：prompt 与 D0 完全相同，controller 用持久 AffordanceState 直接
     以历史事实回答 READ(t_deepseek_thread)（模型未被任何文字告知）；
  D2b 结构承接 + 过期：历史过期后 controller 重新真实读取（不永久僵化）。

分批用法：PYTHONPATH="phase0;web" phase0/.venv/Scripts/python.exe web/experiments/run_plasticity.py [ep1|D0|D1|D2|D2b]
不带参数按顺序全跑。ep1 必须先于 D1/D2/D2b。
"""

from __future__ import annotations

import asyncio
import json
import sys
import time
from datetime import datetime, timezone
from pathlib import Path
from tempfile import TemporaryDirectory

ROOT = Path(__file__).resolve().parents[2]
WEB_DIR = ROOT / "web"
sys.path.insert(0, str(WEB_DIR))
sys.path.insert(0, str(ROOT / "phase0"))

from deepseek_runtime import DeepSeekRuntimeAdapter, DeepSeekStateStore, load_deepseek_config
from world_harness import (
    SYSTEM_PROMPT,
    AffordanceStateStore,
    WorldController,
    hash_text,
    run_episode,
)

VAULT = Path.home() / "Documents" / "Obsidian Vault"
HISTORY = VAULT / "10 Projects" / "Cognos" / "运行数据" / "第一发生场.jsonl"
THREAD_STATE = WEB_DIR / ".state" / "deepseek_thread.json"
SCOPE = "deepseek_reply_integrity"
CAP = 3000

EXPERIMENTS_DIR = WEB_DIR / ".state" / "experiments"
AFFORDANCE_FILE = EXPERIMENTS_DIR / "plasticity_affordance.json"
EP1_FILE = EXPERIMENTS_DIR / "plasticity_ep1.json"
RUNNING_FILE = EXPERIMENTS_DIR / "plasticity_running.json"

QUESTION_EP1 = (
    "第一发生场（本地运行记录）中 occurrence 12d26518（2026-08-20T05:25，作者 DeepSeek）"
    "保存的 content 是一段模型输出的 JSON 信封原文，而不是解析后的 answer；它的 orientation "
    "字段为空。已知该 content 是一段在 1040 字符处被截断、无法闭合的 JSON。"
    "请调查：这个截断最可能发生在哪一层（模型/服务商、runtime 适配器、服务器解析与存储），"
    "以及为什么系统在保存时没有留下解析失败的标记？先不要声称你已经读取任何文件。"
)

QUESTION_EP2 = (
    "第一发生场中 occurrence 28f72720（DeepSeek 的一条回复）保存的 content 是解析后的 "
    "answer 文本。请核验：web/.state/deepseek_thread.json 中与该回复对应的原始模型输出"
    "是否存在、其 orientation 信封是否完整可解析、并与 occurrence 28f72720 的 content "
    "是否一致；如果无法核对，说明缺少什么材料。先不要声称你已经读取任何文件。"
)

TOOL_SCHEMA = (
    "可用 action（每次一个，可多次）：\n"
    + "READ(t_occurrence)：读取第一发生场中指定的 occurrence 原始记录。\n"
    + "READ(t_deepseek_thread)：读取 web/.state/deepseek_thread.json 中对应的原始模型回复记录。\n"
    + "READ(t_parse_code)：读取当前代码中负责解析信封与发送 DeepSeek 请求的函数源码。\n"
    + "注意：action 的 target 只能是 t_occurrence、t_deepseek_thread、t_parse_code 三者之一，不要附加任何参数或说明。"
)


def occurrence_text(occurrence_id: str) -> str:
    rows = [json.loads(l) for l in HISTORY.read_text(encoding="utf-8").splitlines() if l.strip()]
    for row in rows:
        if row.get("id") == occurrence_id:
            return json.dumps({"source": f"vault:{HISTORY}", "record": row}, ensure_ascii=False, indent=2)[:CAP]
    raise FileNotFoundError(f"occurrence {occurrence_id} 不在第一发生场中")


def read_thread_turn() -> str:
    state = json.loads(THREAD_STATE.read_text(encoding="utf-8"))
    out = {"source": str(THREAD_STATE), "turns": []}
    for index, message in enumerate(state.get("contents", [])):
        text = str(message.get("content") or "")
        try:
            obj = json.loads(text)
            parsed_ok = True
        except Exception:
            obj = None
            parsed_ok = False
        out["turns"].append(
            {
                "index": index,
                "role": message.get("role"),
                "len": len(text),
                "head": text[:80],
                "envelope_parses": parsed_ok,
                "answer_head": (obj or {}).get("answer", "")[:80] if parsed_ok else None,
            }
        )
    return json.dumps(out, ensure_ascii=False, indent=2)[:CAP]


def read_parse_code() -> str:
    situation = (WEB_DIR / "situation.py").read_text(encoding="utf-8")
    start = situation.index("def parse_orientation")
    end = situation.index("def format_questions")
    parse_code = situation[start:end]
    deepseek = (ROOT / "phase0" / "deepseek_runtime.py").read_text(encoding="utf-8")
    send_start = deepseek.index("    async def send")
    send_end = deepseek.index("        state.contents.append", send_start)
    send_code = deepseek[send_start:send_end]
    return (
        "来源：web/situation.py 的 parse_orientation：\n" + parse_code
        + "\n\n来源：phase0/deepseek_runtime.py 的 send()（至保存状态前）：\n" + send_code
    )[:3500]


def make_targets(occurrence_id: str = "28f72720-0007-4cc1-bd35-3b7395ec6e79") -> dict:
    return {
        "t_occurrence": lambda: occurrence_text(occurrence_id),
        "t_deepseek_thread": read_thread_turn,
        "t_parse_code": read_parse_code,
    }


EP1_OCCURRENCE_ID = "12d26518-7dd5-498c-b2d4-9b20f3521308"
EP2_OCCURRENCE_ID = "28f72720-0007-4cc1-bd35-3b7395ec6e79"


def derive_semantic_note(episode1_trace: dict) -> str:
    """从 episode 1 的 trace 机械提取真实遭遇，写成普通文字（不写认识论）。

    优先取已注册 target 的真实拒绝（排除 unknown_target 这类协议错误）。"""

    for turn in episode1_trace.get("turns", []):
        result = turn.get("world_result") or {}
        if result.get("status") == "denied" and result.get("error") != "unknown_target":
            return (
                "历史经验（文字形式，来自真实运行记录）：上一轮同范围调查中，"
                f"READ({result.get('target')}) 被外部 controller 拒绝（error="
                f"{result.get('error')}，provenance={result.get('provenance')}）。"
            )
    for turn in episode1_trace.get("turns", []):
        result = turn.get("world_result") or {}
        if result.get("status") == "denied":
            return (
                "历史经验（文字形式，来自真实运行记录）：上一轮同范围调查中，"
                f"READ({result.get('target')}) 被外部 controller 拒绝（error="
                f"{result.get('error')}）。"
            )
    final = episode1_trace.get("final_response") or ""
    return "历史经验（文字形式）：上一轮调查没有遇到外部拒绝。" + final[:200]


def save_json(path: Path, payload: dict) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


def load_json(path: Path) -> dict:
    if path.exists():
        try:
            return json.loads(path.read_text(encoding="utf-8"))
        except (OSError, json.JSONDecodeError):
            pass
    return {}


async def main() -> None:
    cfg = load_deepseek_config()
    if not cfg:
        raise SystemExit("无 DeepSeek (opencode-go) 凭据；无法运行真实实验")

    only = sys.argv[1] if len(sys.argv) > 1 else None
    steps = ["ep1", "D0", "D1", "D2", "D2b"] if not only else [only]

    with TemporaryDirectory(prefix="cognos-plasticity-") as td:
        store = AffordanceStateStore(AFFORDANCE_FILE)

        if "ep1" in steps:
            controller = WorldController(
                targets=make_targets(EP1_OCCURRENCE_ID),
                policy={"t_deepseek_thread": {"mode": "deny", "deny_reason": "read restricted"}},
                affordance_state=store,
                scope=SCOPE,
            )
            adapter = DeepSeekRuntimeAdapter(
                api_key=cfg["api_key"], base_url=cfg["base_url"], model=cfg["model"],
                model_name=cfg["model_name"], developer_instructions=SYSTEM_PROMPT,
                state_store=DeepSeekStateStore(Path(td) / "ep1.json"),
            )
            start = time.time()
            ep1 = await run_episode(adapter, controller, question=QUESTION_EP1, tool_schema=TOOL_SCHEMA, max_turns=3)
            ep1["condition_id"] = "episode1"
            ep1["elapsed_seconds"] = round(time.time() - start, 1)
            note = derive_semantic_note(ep1)
            save_json(EP1_FILE, {"trace": ep1, "semantic_note": note, "model": cfg["model"],
                                 "saved_at": datetime.now(timezone.utc).isoformat()})
            print("episode1: actions=%d turns=%d note=%s" % (ep1["action_count"], len(ep1["turns"]), note[:80]), flush=True)

        ep1_payload = load_json(EP1_FILE)
        semantic_note = ep1_payload.get("semantic_note") or ""
        d1_question = QUESTION_EP2 + "\n\n" + semantic_note if semantic_note else QUESTION_EP2

        progress = load_json(RUNNING_FILE)
        results = progress.get("results", [])
        existing = {(r.get("condition_id"), r.get("repetition")) for r in results}

        for step in steps:
            if step == "ep1":
                continue
            if step == "D1" and not semantic_note:
                print("D1 需要先跑 ep1（没有语义笔记来源）", flush=True)
                continue
            if step in ("D2", "D2b") and not (load_json(AFFORDANCE_FILE) or {}).get("scopes"):
                print(f"{step} 需要先跑 ep1（没有持久 AffordanceState）", flush=True)
                continue
            for rep in range(1, 3):
                if (step, rep) in existing:
                    print(f"skip {step} rep{rep}（已存在）", flush=True)
                    continue
                use_history = step in ("D2", "D2b")
                controller = WorldController(
                    targets=make_targets(EP2_OCCURRENCE_ID),
                    affordance_state=store if use_history else None,
                    scope=SCOPE if use_history else None,
                )
                if step == "D2b":
                    store.record(
                        SCOPE, "t_deepseek_thread",
                        {"status": "denied", "error": "read restricted",
                         "provenance": "controller policy (world side)"},
                        expiry_seconds=-1,
                    )
                question = d1_question if step == "D1" else QUESTION_EP2
                adapter = DeepSeekRuntimeAdapter(
                    api_key=cfg["api_key"], base_url=cfg["base_url"], model=cfg["model"],
                    model_name=cfg["model_name"], developer_instructions=SYSTEM_PROMPT,
                    state_store=DeepSeekStateStore(Path(td) / f"{step}-{rep}.json"),
                )
                start = time.time()
                trace = await run_episode(adapter, controller, question=question, tool_schema=TOOL_SCHEMA, max_turns=3)
                trace["condition_id"] = step
                trace["repetition"] = rep
                trace["question_kind"] = "semantic" if step == "D1" else "plain"
                trace["use_history"] = use_history
                trace["elapsed_seconds"] = round(time.time() - start, 1)
                results.append(trace)
                save_json(RUNNING_FILE, {
                    "experiment": "cross_episode_plasticity",
                    "updated_at": datetime.now(timezone.utc).isoformat(),
                    "model": cfg["model"],
                    "question_ep2_plain_sha256": hash_text(QUESTION_EP2),
                    "semantic_note": semantic_note,
                    "tool_schema_sha256": hash_text(TOOL_SCHEMA),
                    "scope": SCOPE,
                    "results": results,
                })
                first = trace["turns"][0].get("proposed_action") or {}
                print(
                    f"{step} rep{rep}: actions={trace['action_count']} "
                    f"first={first.get('target') if first else 'final'} "
                    f"turns={len(trace['turns'])} elapsed={trace['elapsed_seconds']}s",
                    flush=True,
                )

    if only is None:
        final = RUNNING_FILE.with_name(f"plasticity_{datetime.now(timezone.utc).strftime('%Y%m%d-%H%M%S')}.json")
        final.write_text(RUNNING_FILE.read_text(encoding="utf-8"))
        print("saved", final, flush=True)


if __name__ == "__main__":
    asyncio.run(main())
