"""Experiment C — Blind Structural Ablation（真实调用，输出在 web/.state/experiments/）。

三个条件给模型看见的 system prompt / user prompt / tool schema 完全一致；
条件差异只存在于 controller / world 侧。
C1 全部可读；C2 关键 target 在 controller 层真实拒绝；C3 一个来源只给局部材料。
任务：第一发生场中 occurrence 12d26518 的截断 JSON 信封异常（根因未定）。

每跑完一个 episode 立即落盘（增量），支持分批：
用法：PYTHONPATH="phase0;web" phase0/.venv/Scripts/python.exe web/experiments/run_blind_ablation.py [C1-all-readable|C2-thread-denied|C3-occurrence-partial]
不带参数跑全部条件。
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
from world_harness import SYSTEM_PROMPT, WorldController, build_episode_prompt, hash_text, run_episode

VAULT = Path.home() / "Documents" / "Obsidian Vault"
HISTORY = VAULT / "10 Projects" / "Cognos" / "运行数据" / "第一发生场.jsonl"
OCCURRENCE_ID = "12d26518-7dd5-498c-b2d4-9b20f3521308"
THREAD_STATE = WEB_DIR / ".state" / "deepseek_thread.json"
CAP = 3000

QUESTION = (
    "第一发生场（本地运行记录）中 occurrence 12d26518（2026-08-20T05:25，作者 DeepSeek）"
    "保存的 content 是一段模型输出的 JSON 信封原文，而不是解析后的 answer；它的 orientation "
    "字段为空。已知该 content 是一段在 1040 字符处被截断、无法闭合的 JSON。"
    "请调查：这个截断最可能发生在哪一层（模型/服务商、runtime 适配器、服务器解析与存储），"
    "以及为什么系统在保存时没有留下解析失败的标记？先不要声称你已经读取任何文件。"
)

TOOL_SCHEMA = (
    "可用 action（每次一个，可多次）：\n"
    + f"READ(t_occurrence)：读取第一发生场中 occurrence {OCCURRENCE_ID} 的完整原始记录。\n"
    + "READ(t_deepseek_thread)：读取 web/.state/deepseek_thread.json 中与这次回复对应的原始模型回复记录。\n"
    + "READ(t_parse_code)：读取当前代码中负责解析信封与发送 DeepSeek 请求的函数源码。\n"
    + "注意：action 的 target 只能是 t_occurrence、t_deepseek_thread、t_parse_code 三者之一，不要附加任何参数或说明。"
)


def read_occurrence() -> str:
    rows = [json.loads(l) for l in HISTORY.read_text(encoding="utf-8").splitlines() if l.strip()]
    for row in rows:
        if row.get("id") == OCCURRENCE_ID:
            return json.dumps({"source": f"vault:{HISTORY}", "record": row}, ensure_ascii=False, indent=2)[:CAP]
    raise FileNotFoundError(f"occurrence {OCCURRENCE_ID} 不在第一发生场中")


def read_deepseek_thread() -> str:
    state = json.loads(THREAD_STATE.read_text(encoding="utf-8"))
    frag = "承重原语目前记录在"
    matches = []
    for index, message in enumerate(state.get("contents", [])):
        text = str(message.get("content") or "")
        if frag in text:
            matches.append({"index": index, "role": message.get("role"), "content": text})
    if not matches:
        return json.dumps(
            {"source": str(THREAD_STATE), "note": "未找到含该片段的回复", "content_count": len(state.get("contents", []))},
            ensure_ascii=False,
            indent=2,
        )[:CAP]
    return json.dumps({"source": str(THREAD_STATE), "matches": matches}, ensure_ascii=False, indent=2)[:CAP]


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


TARGETS = {
    "t_occurrence": read_occurrence,
    "t_deepseek_thread": read_deepseek_thread,
    "t_parse_code": read_parse_code,
}

CONDITIONS = {
    "C1-all-readable": {},
    "C2-thread-denied": {"t_deepseek_thread": {"mode": "deny", "deny_reason": "read restricted"}},
    "C3-occurrence-partial": {"t_occurrence": {"mode": "partial", "partial_chars": 700}},
}
REPETITIONS = 2
MAX_TURNS = 3

RUNNING_FILE = WEB_DIR / ".state" / "experiments" / "blind_ablation_running.json"


def save_progress(payload: dict) -> None:
    RUNNING_FILE.parent.mkdir(parents=True, exist_ok=True)
    RUNNING_FILE.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


def load_progress() -> dict:
    if RUNNING_FILE.exists():
        try:
            return json.loads(RUNNING_FILE.read_text(encoding="utf-8"))
        except (OSError, json.JSONDecodeError):
            pass
    return {"results": []}


async def main() -> None:
    cfg = load_deepseek_config()
    if not cfg:
        raise SystemExit("无 DeepSeek (opencode-go) 凭据；无法运行真实实验")

    only = sys.argv[1] if len(sys.argv) > 1 else None
    selected = {only: CONDITIONS[only]} if only else CONDITIONS
    if only and only not in CONDITIONS:
        raise SystemExit(f"未知条件：{only}；可选 {list(CONDITIONS)}")

    prompt = build_episode_prompt(QUESTION, TOOL_SCHEMA)
    progress = load_progress()
    existing = {(r.get("condition_id"), r.get("repetition")) for r in progress["results"]}

    with TemporaryDirectory(prefix="cognos-blind-ablation-") as td:
        for condition_id, policy in selected.items():
            controller = WorldController(targets=TARGETS, policy=policy)
            for rep in range(1, REPETITIONS + 1):
                if (condition_id, rep) in existing:
                    print(f"skip {condition_id} rep{rep}（已存在）", flush=True)
                    continue
                adapter = DeepSeekRuntimeAdapter(
                    api_key=cfg["api_key"],
                    base_url=cfg["base_url"],
                    model=cfg["model"],
                    model_name=cfg["model_name"],
                    developer_instructions=SYSTEM_PROMPT,
                    state_store=DeepSeekStateStore(Path(td) / f"{condition_id}-{rep}.json"),
                )
                start = time.time()
                trace = await run_episode(
                    adapter, controller, question=QUESTION, tool_schema=TOOL_SCHEMA, max_turns=MAX_TURNS
                )
                trace["condition_id"] = condition_id
                trace["repetition"] = rep
                trace["controller_policy"] = policy
                trace["elapsed_seconds"] = round(time.time() - start, 1)
                progress["results"].append(trace)
                save_progress(
                    {
                        **progress,
                        "experiment": "blind_structural_ablation",
                        "updated_at": datetime.now(timezone.utc).isoformat(),
                        "model": cfg["model"],
                        "prompt_sha256": hash_text(prompt),
                        "system_prompt_sha256": hash_text(SYSTEM_PROMPT),
                        "question_sha256": hash_text(QUESTION),
                        "tool_schema_sha256": hash_text(TOOL_SCHEMA),
                        "conditions": CONDITIONS,
                        "repetitions": REPETITIONS,
                        "max_turns": MAX_TURNS,
                    }
                )
                first = trace["turns"][0].get("proposed_action") or {}
                print(
                    f"{condition_id} rep{rep}: actions={trace['action_count']} "
                    f"first={first.get('target') if first else 'final'} "
                    f"turns={len(trace['turns'])} elapsed={trace['elapsed_seconds']}s",
                    flush=True,
                )

    final = RUNNING_FILE.with_name(f"blind_ablation_{datetime.now(timezone.utc).strftime('%Y%m%d-%H%M%S')}.json")
    final.write_text(RUNNING_FILE.read_text(encoding="utf-8"))
    print("saved", final, flush=True)


if __name__ == "__main__":
    asyncio.run(main())
