"""Bundle state + eval into result.json and copy it into the Nextcloud container's data dir.

The bundle contains the user's own conversation text, so it stays out of git (out/) and out of
the web root; ReadingsController serves it to admins only.

Usage: python publish.py [--container cognos-nextcloud-trial-app-1]
"""
from __future__ import annotations

import argparse
import json
import subprocess
from datetime import datetime
from pathlib import Path

from engine import OUT, load_occurrences

DEST_DIR = "/var/www/html/data/cognos-readings"


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--container", default="cognos-nextcloud-trial-app-1")
    a = ap.parse_args()

    occ = {o["id"]: o for o in load_occurrences()}
    state = json.loads((OUT / "state.json").read_text(encoding="utf-8"))
    ev_path = OUT / "eval.json"
    ev = json.loads(ev_path.read_text(encoding="utf-8")) if ev_path.exists() else None

    used = set()
    for r in state["readings"]:
        used.update(r["support"])
        used.update(a_["occ"] for a_ in r["anomalies"])
    bundle = {
        "subject": state["subject"],
        "trained_until": state["trained_until"],
        "generated_at": datetime.now().strftime("%Y-%m-%d %H:%M"),
        "readings": state["readings"],
        "events": state["events"],
        "occurrences": {i: {"text": occ[i]["text"][:600], "prev_agent": occ[i]["prev_agent"][-300:], "ts": occ[i]["ts"]}
                        for i in used if i in occ},
        "eval": {"summary": ev["summary"], "results": ev["results"]} if ev else None,
    }
    path = OUT / "result.json"
    path.write_text(json.dumps(bundle, ensure_ascii=False), encoding="utf-8")

    run = lambda *cmd: subprocess.run(cmd, check=True)
    run("docker", "exec", a.container, "mkdir", "-p", DEST_DIR)
    run("docker", "cp", str(path), f"{a.container}:{DEST_DIR}/result.json")
    run("docker", "exec", a.container, "chown", "-R", "www-data:www-data", DEST_DIR)
    print(f"published {path.stat().st_size // 1024} KB -> {a.container}:{DEST_DIR}/result.json")


if __name__ == "__main__":
    main()
