"""对照检验：同一批后来的真实发生上，三种记忆谁更能预料用户的反应。

  A = Hermes 现有的总结式记忆 (USER.md，写于训练截止时)
  B = 读法引擎 (out/state.json)
  C = 无记忆 (基线)

每条测试发生：用 agent 刚说的话 + 一种记忆，预测用户会怎么回应；
再由评审在不知道来源的情况下，把三个预测与真实回应对照打分。

Usage: python evaluate.py --until 2026-10-13T09:51 --hermes-user-md <USER.md> [--per-source 60]
Output: out/eval.json
"""
from __future__ import annotations

import argparse
import json
import random
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path

from engine import OUT, SUBJECT, load_occurrences, render_memory
from llm import chat_json

TYPES = ["满意并继续", "简短确认/选择", "纠正理解偏差", "表达不满", "追问深入", "转向新话题/新要求", "其他"]

PREDICT_SYS = "你在预测一个用户对 AI agent 的下一条回复。只根据给你的信息推断。"
JUDGE_SYS = """你是评审。给你 agent 刚说的话、用户的真实回复，以及三个对用户回复的预测（来源不明）。
逐个打分：3=反应类型和实质内容都预料到了；2=方向对，细节偏；1=只沾边；0=没预料到或相反。
不要因为预测写得长或写得好听就加分，只看它是否预料到了真实回复。"""


def pick(items, n):
    if len(items) <= n:
        return items
    step = len(items) / n
    return [items[int(i * step)] for i in range(n)]


def predict(memory: str, o: dict) -> dict:
    prompt = (f"{memory}\n\n---\nagent 刚说：\n{o['prev_agent'][-1200:]}\n\n"
              f"预测用户接下来会怎么回应。type 从 {TYPES} 中选一个。\n"
              '输出 {"type": "...", "gist": "一两句话：他最可能说什么、要什么"}')
    return chat_json(PREDICT_SYS, prompt, max_tokens=600)


def judge(o: dict, preds: dict) -> dict:
    labels = list(preds)
    random.shuffle(labels)
    blind = {str(i + 1): preds[m] for i, m in enumerate(labels)}
    prompt = (f"agent 刚说：\n{o['prev_agent'][-1200:]}\n\n用户真实回复：\n{o['text'][:800]}\n\n预测：\n" +
              "\n".join(f"{k}. [{v.get('type')}] {v.get('gist')}" for k, v in blind.items()) +
              '\n\n输出 {"scores": {"1": n, "2": n, "3": n}, "why": "一句话"}')
    out = chat_json(JUDGE_SYS, prompt, max_tokens=400)
    scores = {labels[int(k) - 1]: int(v) for k, v in out.get("scores", {}).items()}
    return {"scores": scores, "why": out.get("why", "")}


def run_one(o, memories):
    preds = {m: predict(mem, o) for m, mem in memories.items()}
    return {"occ": o["id"], "source": o["source"], "ts": o["ts"], "text": o["text"][:400],
            "prev_agent": o["prev_agent"][-400:], "preds": preds, **judge(o, preds)}


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--until", required=True)
    ap.add_argument("--hermes-user-md", type=Path, required=True)
    ap.add_argument("--per-source", type=int, default=60)
    a = ap.parse_args()
    random.seed(7)

    occs = load_occurrences()
    occ = {o["id"]: o for o in occs}
    state = json.loads((OUT / "state.json").read_text(encoding="utf-8"))
    memories = {
        "A": f"关于{SUBJECT}的记忆：\n" + a.hermes_user_md.read_text(encoding="utf-8").replace("§", ""),
        "B": render_memory(state, occ),
        "C": "（没有任何关于这个用户的信息）",
    }

    def usable(o):
        return o["prev_agent"] and len(o["text"]) >= 6

    tests = (pick([o for o in occs if o["source"] == "hermes" and o["ts"] >= a.until and usable(o)], a.per_source) +
             pick([o for o in occs if o["source"] == "claude-code" and usable(o)], a.per_source))
    print(f"{len(tests)} test occurrences", flush=True)

    results = []
    with ThreadPoolExecutor(6) as ex:
        for i, r in enumerate(ex.map(lambda o: run_one(o, memories), tests), 1):
            results.append(r)
            if i % 10 == 0:
                print(f"  {i}/{len(tests)}", flush=True)

    summary = {}
    for src in ("hermes", "claude-code", "all"):
        rs = [r for r in results if src == "all" or r["source"] == src]
        if not rs:
            continue
        summary[src] = {"n": len(rs), **{m: round(sum(r["scores"].get(m, 0) for r in rs) / len(rs), 3) for m in memories},
                        "B_beats_A": sum(r["scores"].get("B", 0) > r["scores"].get("A", 0) for r in rs),
                        "A_beats_B": sum(r["scores"].get("A", 0) > r["scores"].get("B", 0) for r in rs)}
    (OUT / "eval.json").write_text(json.dumps({"until": a.until, "memories": memories, "summary": summary,
                                               "results": results}, ensure_ascii=False, indent=1), encoding="utf-8")
    print(json.dumps(summary, ensure_ascii=False, indent=1))


if __name__ == "__main__":
    main()
