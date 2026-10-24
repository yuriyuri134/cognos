"""读法引擎 (method B): 发生与读法分离、异常积累、重组。

- occurrences: 原始发生，只读，按 id 引用。
- readings:    对发生的当前读法。永不原地改写；变化只以新读法 + 父子关系出现。
- events:      每一步发生了什么（新读法、支持、异常、重组、裁决、合并），即生长史。

Usage: python -I engine.py --until 2026-10-13T09:51 [--source hermes]
Output: out/state.json
"""
from __future__ import annotations

import argparse
import json
from pathlib import Path

from llm import chat_json

OUT = Path(__file__).parent / "out"
BATCH = 20
ANOMALY_MIN, ANOMALY_RATIO = 3, 0.25
MERGE_EVERY, MERGE_ABOVE = 3, 12

SUBJECT = "小林"

BET_SYS = f"""你只能依据给定的读法，预测主体「{SUBJECT}」在 agent 说完话之后会怎么回应。
你看不到他的真实回复。每条预测都要标明依据了哪些读法；没有读法可依据时 readings 留空。"""

OBSERVE_SYS = f"""你在维护一组关于主体「{SUBJECT}」的读法（reading）。
读法是对原始发生的当前解释，不是事实。原始发生是 {SUBJECT} 对 AI agent 说的原话，附带 agent 刚说过的话作为语境。

每条发生之前，已经有人只凭当前读法押注过他会怎么回应（附在下面，含依据的读法）。现在揭晓真实回复：
- support：押中了，依据的读法得到支持；
- anomaly：没押中，且原因在依据的读法本身（它让人预料了别的、或方向相反）；写清 why。
  押错就是异常，不要事后把真实回复解释成「其实也符合」。
- new：现有读法都没覆盖、导致押不中的东西，提出新读法。

规则：
1. 不改写已有读法，只引用。
2. 新读法必须具体、可被证伪，写出它在什么条件下成立（conditions）。
3. 关注这个人怎么想、要什么、怎么反应、在意什么；不要把一次性的任务细节当成主体特征。
4. 宁缺毋滥：一批里通常 0-3 条新读法。简短的确认（如「可以」「1」）通常不构成异常。"""

REORG_SYS = f"""你在为主体「{SUBJECT}」的一条读法做重组。
这条读法积累了多条异常：用它解释不了、只能靠打补丁加例外。
禁止加例外或把读法改得更含糊。你要找一种不同的切法：一个更深或换了角度的解释，
使支持它的发生和异常的发生都成为这个新解释的自然结果。
如果你判断异常只是噪声或误判，verdict 填 noise。"""

ARBITRATE_SYS = f"""两条关于主体「{SUBJECT}」的读法在竞争：旧读法与由它重组出的新读法。
根据它们各自引用的原始发生，判断：
- replace：新读法解释得更多、例外更少，取代旧读法；
- coexist：两者描述不同条件下的不同表现，都保留（需写明各自条件）；
- reject：新读法没有更好，撤回新读法。"""

MERGE_SYS = f"""下面是关于主体「{SUBJECT}」的若干条当前读法。找出说的是同一件事的近似重复，
把每组合并成一条更准确的读法。不是重复的不要硬合。"""


def load_occurrences():
    return [json.loads(l) for l in (OUT / "occurrences.jsonl").open(encoding="utf-8")]


class Engine:
    def __init__(self, occs):
        self.occ = {o["id"]: o for o in occs}
        self.readings: list[dict] = []
        self.events: list[dict] = []
        self.step = 0
        self.n = 0

    # ---------- helpers
    def rid(self):
        self.n += 1
        return f"r{self.n:03d}"

    def get(self, rid):
        return next((r for r in self.readings if r["id"] == rid), None)

    def current(self):
        return [r for r in self.readings if r["status"] in ("current", "competing")]

    def log(self, kind, **kw):
        self.events.append({"step": self.step, "kind": kind, **kw})

    def new_reading(self, claim, conditions, evidence, origin, parents=(), status="current"):
        r = {"id": self.rid(), "claim": claim, "conditions": conditions, "status": status,
             "origin": origin, "parents": list(parents), "born_step": self.step,
             "support": [e for e in evidence if e in self.occ], "anomalies": [], "note": ""}
        self.readings.append(r)
        self.log("new" if origin == "observe" else origin, reading=r["id"], claim=claim, parents=list(parents))
        return r

    def quote(self, oid, n=220):
        o = self.occ[oid]
        return f"[{oid}] agent刚说: {o['prev_agent'][-160:]}\n        {SUBJECT}说: {o['text'][:n]}"

    def render_readings(self, rs):
        return "\n".join(
            f"{r['id']} ({'竞争中' if r['status']=='competing' else '当前'}, 支持{len(r['support'])} 异常{len(r['anomalies'])}): "
            f"{r['claim']}  | 条件: {r['conditions']}" for r in rs) or "(还没有读法)"

    # ---------- steps
    def bet(self, batch):
        """押注：只给 agent 的话，不给真实回复，凭当前读法预测。"""
        if not self.current():
            return {}
        prompt = ("当前读法：\n" + self.render_readings(self.current()) + "\n\n" +
                  "\n".join(f"[{o['id']}] agent刚说: {o['prev_agent'][-400:]}" for o in batch) +
                  '\n\n输出 {"bets":[{"occ","expect":"一句话","readings":[reading id]}]}')
        out = chat_json(BET_SYS, prompt, max_tokens=8000)
        return {b["occ"]: b for b in out.get("bets", []) if b.get("occ")}

    def observe(self, batch):
        bets = self.bet(batch)
        self.last_bets = bets

        def line(o):
            b = bets.get(o["id"])
            bet = f"\n        押注: {b.get('expect','')}（依据 {', '.join(b.get('readings') or []) or '无'}）" if b else ""
            return f"[{o['id']}] agent刚说: {o['prev_agent'][-400:]}{bet}\n        {SUBJECT}真实说: {o['text'][:600]}"

        prompt = ("当前读法：\n" + self.render_readings(self.current()) + "\n\n本批原始发生：\n" +
                  "\n".join(line(o) for o in batch) +
                  '\n\n输出 {"support":[{"occ","reading"}],"anomaly":[{"occ","reading","why"}],'
                  '"new":[{"claim","conditions","evidence":[occ id]}]}')
        out = chat_json(OBSERVE_SYS, prompt, max_tokens=12000)
        for s in out.get("support", []):
            r = self.get(s.get("reading"))
            if r and s.get("occ") in self.occ and s["occ"] not in r["support"]:
                r["support"].append(s["occ"])
        for a in out.get("anomaly", []):
            r = self.get(a.get("reading"))
            if r and a.get("occ") in self.occ:
                r["anomalies"].append({"occ": a["occ"], "why": a.get("why", "")})
                self.log("anomaly", reading=r["id"], occ=a["occ"], why=a.get("why", ""))
        for n in out.get("new", []):
            if n.get("claim"):
                self.new_reading(n["claim"], n.get("conditions", ""), n.get("evidence", []), "observe")

    def maybe_reorganize(self):
        for r in list(self.current()):
            if r["status"] != "current" or any(c["parents"] == [r["id"]] and c["status"] == "competing"
                                               for c in self.readings):
                continue
            na, ns = len(r["anomalies"]), len(r["support"])
            if na < ANOMALY_MIN or na / max(1, na + ns) < ANOMALY_RATIO:
                continue
            prompt = (f"读法 {r['id']}: {r['claim']}\n条件: {r['conditions']}\n\n支持它的发生：\n" +
                      "\n".join(self.quote(o) for o in r["support"][-8:]) +
                      "\n\n异常（它解释不了的）：\n" +
                      "\n".join(self.quote(a["occ"]) + f"\n        异常原因: {a['why']}" for a in r["anomalies"][-8:]) +
                      '\n\n输出 {"verdict":"reorganize|noise","claim","conditions","what_changed","explains":[occ id]}')
            out = chat_json(REORG_SYS, prompt)
            if out.get("verdict") == "noise":
                r["note"] = "异常被判为噪声: " + out.get("what_changed", "")
                r["anomalies_cleared"] = r.get("anomalies_cleared", 0) + len(r["anomalies"])
                r["anomalies"] = []
                self.log("noise", reading=r["id"], why=out.get("what_changed", ""))
                continue
            evidence = list(dict.fromkeys(out.get("explains", []) or (r["support"] + [a["occ"] for a in r["anomalies"]])))
            child = self.new_reading(out["claim"], out.get("conditions", ""), evidence, "reorganize",
                                     parents=[r["id"]], status="competing")
            child["what_changed"] = out.get("what_changed", "")

    def arbitrate(self):
        for c in [r for r in self.readings if r["status"] == "competing" and r["born_step"] < self.step]:
            p = self.get(c["parents"][0])
            evid = list(dict.fromkeys(p["support"] + [a["occ"] for a in p["anomalies"]] + c["support"]))[-14:]
            prompt = (f"旧读法 {p['id']}: {p['claim']} | 条件: {p['conditions']}\n"
                      f"新读法 {c['id']}: {c['claim']} | 条件: {c['conditions']}\n重组时说明: {c.get('what_changed','')}\n\n"
                      "相关原始发生：\n" + "\n".join(self.quote(o) for o in evid) +
                      '\n\n输出 {"decision":"replace|coexist|reject","why","old_conditions","new_conditions"}')
            out = chat_json(ARBITRATE_SYS, prompt)
            d = out.get("decision")
            if d == "replace":
                p["status"], c["status"] = "retired", "current"
                p["retired_by"], p["retired_why"] = c["id"], out.get("why", "")
            elif d == "coexist":
                c["status"] = "current"
                if out.get("old_conditions"):
                    p["conditions"] = out["old_conditions"]  # 条件被收窄，记入事件
                if out.get("new_conditions"):
                    c["conditions"] = out["new_conditions"]
                p["anomalies"] = []
            else:
                c["status"], c["retired_why"] = "retired", out.get("why", "")
            self.log("arbitrate", parent=p["id"], child=c["id"], decision=d, why=out.get("why", ""))

    def maybe_merge(self):
        cur = [r for r in self.readings if r["status"] == "current"]
        if self.step % MERGE_EVERY or len(cur) <= MERGE_ABOVE:
            return
        out = chat_json(MERGE_SYS, self.render_readings(cur) +
                        '\n\n输出 {"groups":[{"ids":[reading id],"claim","conditions"}]}，没有重复就输出 {"groups":[]}')
        for g in out.get("groups", []):
            members = [self.get(i) for i in g.get("ids", []) if self.get(i) and self.get(i)["status"] == "current"]
            if len(members) < 2:
                continue
            evidence = list(dict.fromkeys(e for m in members for e in m["support"]))
            merged = self.new_reading(g["claim"], g.get("conditions", ""), evidence, "merge",
                                      parents=[m["id"] for m in members])
            for m in members:
                m["status"], m["retired_by"], m["retired_why"] = "merged", merged["id"], "合并为 " + merged["id"]

    def resume(self):
        """Continue from out/state.json: steps already saved are not replayed."""
        st = json.loads((OUT / "state.json").read_text(encoding="utf-8"))
        self.step, self.readings, self.events = st["step"], st["readings"], st["events"]
        self.n = max((int(r["id"][1:]) for r in self.readings), default=0)

    def run(self, occs):
        for i in range(self.step * BATCH, len(occs), BATCH):
            self.step += 1
            batch = occs[i:i + BATCH]
            self.observe(batch)
            self.arbitrate()
            self.maybe_reorganize()
            self.maybe_merge()
            cur = [r for r in self.readings if r["status"] == "current"]
            print(f"step {self.step}: {batch[0]['ts'][:10]}..{batch[-1]['ts'][:10]}  "
                  f"current={len(cur)} total={len(self.readings)} events={len(self.events)}", flush=True)
            self.save(batch[-1]["ts"])

    def save(self, until_ts=""):
        (OUT / "state.json").write_text(json.dumps({
            "subject": SUBJECT, "step": self.step, "trained_until": until_ts,
            "readings": self.readings, "events": self.events}, ensure_ascii=False, indent=1), encoding="utf-8")


def render_memory(state, occ) -> str:
    """Method B memory as handed to a predictor: current readings + raw quotes + overturned readings."""
    cur = sorted([r for r in state["readings"] if r["status"] == "current"], key=lambda r: -len(r["support"]))
    lines = [f"关于{SUBJECT}的当前读法（每条附原话证据）："]
    for r in cur:
        lines.append(f"- {r['claim']}（成立条件：{r['conditions']}；证据 {len(r['support'])} 条）")
        for o in r["support"][:2]:
            lines.append(f"    原话：「{occ[o]['text'][:120]}」")
    old = [r for r in state["readings"] if r["status"] == "retired" and r.get("retired_why")]
    if old:
        lines.append("\n曾经的读法及被推翻的原因：")
        for r in old:
            lines.append(f"- 曾认为：{r['claim']} → 被推翻：{r['retired_why']}")
    return "\n".join(lines)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--until", required=True)
    ap.add_argument("--source", default="hermes")
    ap.add_argument("--resume", action="store_true", help="continue from out/state.json")
    a = ap.parse_args()
    occs = [o for o in load_occurrences() if o["source"] == a.source and o["ts"] < a.until]
    e = Engine(load_occurrences())
    if a.resume:
        e.resume()
    e.run(occs)
    print(render_memory(json.loads((OUT / "state.json").read_text(encoding="utf-8")), e.occ)[:3000])


if __name__ == "__main__":
    main()
