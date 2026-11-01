#!/usr/bin/env python3
"""把桥存下的运行按标签分组对照（见 docs/条件B对照-2026-11-01.md）。

用法：
  python compare_runs.py A B                 每组每次一行：用时、用了哪些工具、回答开头
  python compare_runs.py A B --diff          再把两组第 i 次的 system.txt 做 diff（看输入到底差在哪）
  python compare_runs.py A B --blind out.md  把两组的回答打乱成 X/Y 写进 out.md，标签写进 out.key.json，先读再看答案

只用标准库。运行目录默认 deploy/nextcloud-trial/evidence/claude-bridge/runs（或 COGNOS_RUN_LOG_DIR）。
"""
from __future__ import annotations

import argparse
import collections
import difflib
import json
import os
import random
from pathlib import Path

HERE = Path(__file__).resolve().parent
RUNS = Path(os.environ.get("COGNOS_RUN_LOG_DIR") or HERE.parent.parent / "deploy" / "nextcloud-trial" / "evidence" / "claude-bridge" / "runs")


def load(label: str) -> list[dict]:
    runs = []
    for meta_file in sorted(RUNS.glob("*/meta.json")):
        meta = json.loads(meta_file.read_text(encoding="utf-8"))
        if meta.get("label") != label:
            continue
        d = meta_file.parent
        tools: collections.Counter = collections.Counter()
        tail: list[str] = []
        for line in (d / "stream.jsonl").read_text(encoding="utf-8").splitlines():
            try:
                event = json.loads(line)
            except ValueError:
                continue
            if event.get("type") != "assistant":
                continue
            for block in (event.get("message") or {}).get("content") or []:
                if block.get("type") == "tool_use":
                    tools[block.get("name", "?").replace("mcp__cognos-space__", "")] += 1
                    tail = []
                elif block.get("type") == "text" and block.get("text", "").strip():
                    tail.append(block["text"].strip())
        runs.append({"dir": d, "meta": meta, "tools": tools, "answer": "\n\n".join(tail),
                     "system": (d / "system.txt").read_text(encoding="utf-8"),
                     "prompt": (d / "prompt.txt").read_text(encoding="utf-8")})
    return runs


def summary(label: str, runs: list[dict]) -> None:
    print(f"== {label}：{len(runs)} 次")
    for i, r in enumerate(runs, 1):
        m = r["meta"]
        head = " / ".join(line.strip() for line in r["answer"].splitlines() if line.strip())[:120]
        tools = "、".join(f"{k}×{v}" for k, v in r["tools"].most_common()) or "没用工具"
        print(f"{i}. {r['dir'].name}  {m.get('seconds')}s  提交 {m.get('body_commit')}  入口 {len(r['system'])} 字")
        print(f"   工具：{tools}")
        print(f"   回答 {len(r['answer'])} 字，开头：{head}")


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("labels", nargs=2)
    ap.add_argument("--diff", action="store_true")
    ap.add_argument("--blind")
    args = ap.parse_args()
    groups = {label: load(label) for label in args.labels}
    for label, runs in groups.items():
        summary(label, runs)
    a, b = (groups[label] for label in args.labels)
    if args.diff:
        for i, (x, y) in enumerate(zip(a, b), 1):
            print(f"\n== 第 {i} 次 system.txt：{args.labels[0]} → {args.labels[1]}")
            print("".join(difflib.unified_diff(x["system"].splitlines(True), y["system"].splitlines(True), n=1)))
    if args.blind:
        out, key = [], []
        for i, (x, y) in enumerate(zip(a, b), 1):
            pair = [(args.labels[0], x), (args.labels[1], y)]
            random.shuffle(pair)
            key.append({"pair": i, "X": pair[0][0], "Y": pair[1][0]})
            out.append(f"# 第 {i} 件\n\n## 原话\n\n{x['prompt'].split('现在说', 1)[-1].strip()}\n")
            for name, (_, r) in zip("XY", pair):
                out.append(f"## {name}\n\n{r['answer']}\n")
        Path(args.blind).write_text("\n".join(out), encoding="utf-8")
        Path(args.blind).with_suffix(".key.json").write_text(json.dumps(key, ensure_ascii=False, indent=2), encoding="utf-8")
        print(f"\n盲读文件：{args.blind}（标签在 {Path(args.blind).with_suffix('.key.json')}，读完再看）")


if __name__ == "__main__":
    main()
