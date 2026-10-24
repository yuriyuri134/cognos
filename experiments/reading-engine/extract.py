"""Extract immutable occurrences (用户的原话 + 它在回应的 agent 上一条话) from real sessions.

Sources:
  - Hermes state.db  (sqlite copy)
  - Claude Code transcripts (*.jsonl)

Output: out/occurrences.jsonl  — one occurrence per line, never edited afterwards.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import re
import sqlite3
from datetime import datetime
from pathlib import Path

OUT = Path(__file__).parent / "out"
NOISE = re.compile(r"^\s*(\[IMPORTANT|\[SYSTEM|<command-|<local-command|<system-reminder|<task-notification|/[a-z-]+\s*$)")
PREV_LIMIT = 1500


def occ_id(source: str, key: str) -> str:
    return "o-" + hashlib.sha1(f"{source}:{key}".encode()).hexdigest()[:10]


def clean(text: str) -> str:
    return re.sub(r"\s+", " ", text or "").strip()


def from_hermes(db_path: Path):
    con = sqlite3.connect(db_path)
    sessions = con.execute(
        "select id from sessions where coalesce(title,'') not like 'Subagent%' order by started_at"
    ).fetchall()
    for (sid,) in sessions:
        prev = ""
        rows = con.execute(
            "select id, role, content, timestamp from messages where session_id=? order by timestamp, rowid",
            (sid,),
        ).fetchall()
        for mid, role, content, ts in rows:
            text = clean(content if isinstance(content, str) else "")
            if role == "assistant" and text:
                prev = text
            elif role == "user" and text and not NOISE.match(text):
                yield {
                    "id": occ_id("hermes", str(mid)),
                    "source": "hermes",
                    "session": sid,
                    "ts": datetime.fromtimestamp(ts).isoformat(timespec="seconds"),
                    "text": text,
                    "prev_agent": prev[-PREV_LIMIT:],
                }


def _blocks_text(content) -> tuple[str, bool]:
    """Return (text, is_tool_result)."""
    if isinstance(content, str):
        return content, False
    parts, tool = [], False
    for b in content or []:
        if b.get("type") == "text":
            parts.append(b.get("text", ""))
        elif b.get("type") == "tool_result":
            tool = True
    return "\n".join(parts), tool


def from_claude_code(path: Path):
    prev = ""
    for line in path.read_text(encoding="utf-8").splitlines():
        try:
            d = json.loads(line)
        except json.JSONDecodeError:
            continue
        msg = d.get("message") or {}
        text, is_tool = _blocks_text(msg.get("content"))
        text = clean(text)
        if d.get("type") == "assistant" and text:
            prev = text
        elif d.get("type") == "user" and text and not is_tool and not d.get("isMeta") and not NOISE.match(text):
            yield {
                "id": occ_id("claude-code", d.get("uuid", text)),
                "source": "claude-code",
                "session": path.stem,
                "ts": (d.get("timestamp") or "")[:19],
                "text": text,
                "prev_agent": prev[-PREV_LIMIT:],
            }


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--hermes-db", type=Path, required=True)
    ap.add_argument("--claude-code", type=Path, nargs="*", default=[])
    a = ap.parse_args()

    occs = list(from_hermes(a.hermes_db))
    for p in a.claude_code:
        occs.extend(from_claude_code(p))
    occs.sort(key=lambda o: o["ts"])

    OUT.mkdir(exist_ok=True)
    with (OUT / "occurrences.jsonl").open("w", encoding="utf-8") as f:
        for o in occs:
            f.write(json.dumps(o, ensure_ascii=False) + "\n")
    by_src = {}
    for o in occs:
        by_src[o["source"]] = by_src.get(o["source"], 0) + 1
    print(f"{len(occs)} occurrences", by_src)


if __name__ == "__main__":
    main()
