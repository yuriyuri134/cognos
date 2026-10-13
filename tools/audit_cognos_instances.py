"""Read-only inventory of Cognos source, runtime state, and known local ports.

This is an operational audit, not a migration tool. It never opens a database for
writing, never imports or moves raw history, and never starts or stops a process.
The report intentionally records metadata and hashes rather than credentials or
message bodies.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
import socket
import subprocess
import sys
import tempfile
from datetime import datetime, timezone
from pathlib import Path
from typing import Any
from urllib.error import URLError
from urllib.request import Request, urlopen

REPO = Path(__file__).resolve().parents[1]
PRIMARY_VAULT = Path.home() / "Documents" / "Obsidian Vault"
TRIAL_DIR = REPO / "deploy" / "nextcloud-trial"
TRIAL_STATE = TRIAL_DIR / ".cognos-state"
TRIAL_VAULT = TRIAL_DIR / ".cognos-vault"

KNOWN_PORTS = {
    8765: "primary-local Cognos",
    8775: "trial-nextcloud Cognos",
    8776: "trial-nextcloud webhook proxy",
    8780: "trial-nextcloud Nextcloud",
    8876: "temporary test service",
    8877: "temporary notes test service",
    8878: "temporary notes test service",
    8879: "temporary UI test service",
}


def sha256(path: Path) -> str | None:
    if not path.is_file():
        return None
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def line_count(path: Path) -> int | None:
    if not path.is_file():
        return None
    with path.open("rb") as stream:
        return sum(1 for _ in stream)


def path_record(path: Path) -> dict[str, Any]:
    record: dict[str, Any] = {"path": str(path), "exists": path.exists(), "is_dir": path.is_dir()}
    if path.is_file():
        stat = path.stat()
        record.update({"bytes": stat.st_size, "sha256": sha256(path)})
    elif path.is_dir():
        try:
            record["children"] = sorted(item.name for item in path.iterdir() if item.name != "__pycache__")
        except OSError as exc:
            record["children_error"] = type(exc).__name__
    return record


def is_listening(port: int) -> bool:
    try:
        with socket.create_connection(("127.0.0.1", port), timeout=0.4):
            return True
    except OSError:
        return False


def probe(url: str, kind: str = "generic") -> dict[str, Any]:
    """Probe status without persisting response bodies.

    A bootstrap response contains raw conversation content, so even a local
    audit report must never serialize it. Only small, explicitly selected
    operational fields are retained.
    """
    request = Request(url, headers={"User-Agent": "cognos-instance-audit/1"})
    try:
        with urlopen(request, timeout=3) as response:
            payload = response.read(256 * 1024)
            result: dict[str, Any] = {
                "url": url,
                "status": response.status,
                "content_type": response.headers.get("content-type"),
                "bytes": len(payload),
            }
            if kind in {"health", "bootstrap"}:
                try:
                    data = json.loads(payload.decode("utf-8"))
                except (UnicodeDecodeError, json.JSONDecodeError):
                    result["json_valid"] = False
                else:
                    result["json_valid"] = True
                    if kind == "health":
                        result["reported_status"] = data.get("status") if isinstance(data, dict) else None
                    elif isinstance(data, dict):
                        state = data.get("state") if isinstance(data.get("state"), dict) else {}
                        result["history_count"] = state.get("history_count")
                        result["instance_name"] = state.get("instance_name")
                        result["instance_role"] = state.get("instance_role")
                        result["participant_count"] = len(data.get("participants", [])) if isinstance(data.get("participants"), list) else None
            return result
    except Exception as exc:  # Audit must report unavailable services rather than fail early.
        return {"url": url, "status": None, "error": type(exc).__name__}


def public_state() -> dict[str, Any]:
    path = TRIAL_DIR / ".public-access-state.json"
    if not path.is_file():
        return {"path": str(path), "exists": False}
    try:
        raw = json.loads(path.read_text(encoding="utf-8-sig"))
        allowed = {
            key: raw.get(key)
            for key in (
                "instance_name",
                "instance_role",
                "public_host",
                "cognos_pid",
                "proxy_pid",
                "cognos_port",
                "proxy_port",
                "tunnel_names",
            )
            if key in raw
        }
        allowed.update({"path": str(path), "exists": True})
        return allowed
    except (OSError, json.JSONDecodeError) as exc:
        return {"path": str(path), "exists": True, "error": type(exc).__name__}


def known_temp_dirs() -> list[str]:
    candidates = {Path("/tmp"), Path(tempfile.gettempdir())}
    found: set[str] = set()
    for root in candidates:
        try:
            if root.is_dir():
                found.update(str(path) for path in root.glob("cognos-*") if path.is_dir())
        except OSError:
            continue
    return sorted(found)


def protected_record(path: Path) -> dict[str, Any]:
    return {
        "path": str(path),
        "exists": path.is_file(),
        "bytes": path.stat().st_size if path.is_file() else None,
        "sha256": sha256(path),
    }


def instance(name: str, role: str, state: Path, vault: Path, *, api: str | None = None) -> dict[str, Any]:
    history = vault / "10 Projects" / "Cognos" / "运行数据" / "第一发生场.jsonl"
    result: dict[str, Any] = {
        "name": name,
        "role": role,
        "state": path_record(state),
        "vault": path_record(vault),
        "history": {
            "path": str(history),
            "exists": history.is_file(),
            "lines": line_count(history),
            "sha256": sha256(history),
        },
    }
    if api:
        result["api"] = probe(api + "/api/health", "health")
        result["bootstrap"] = probe(api.rstrip("/") + "/api/bootstrap", "bootstrap")
    return result


def git_summary() -> dict[str, Any]:
    try:
        raw = subprocess.check_output(
            ["git", "-C", str(REPO), "status", "--porcelain=v1", "--untracked-files=all"],
            text=True,
            stderr=subprocess.DEVNULL,
        )
    except (OSError, subprocess.CalledProcessError) as exc:
        return {"available": False, "error": type(exc).__name__}
    lines = [line for line in raw.splitlines() if line.strip()]
    return {
        "available": True,
        "changed_paths": len(lines),
        "tracked_or_modified": sum(not line.startswith("??") for line in lines),
        "untracked": sum(line.startswith("??") for line in lines),
    }


def build_report() -> dict[str, Any]:
    return {
        "report_version": 1,
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "read_only": True,
        "scope_note": "Known Cognos instances and ports only; this is not a claim that every historical experiment directory was inspected.",
        "source_repository": str(REPO),
        "source_repository_exists": REPO.is_dir(),
        "instances": [
            instance("primary-local", "primary", REPO / "web" / ".state", PRIMARY_VAULT, api="http://127.0.0.1:8765"),
            instance("trial-nextcloud", "trial", TRIAL_STATE, TRIAL_VAULT, api="http://127.0.0.1:8775"),
        ],
        "protected_files": [
            protected_record(PRIMARY_VAULT / "10 Projects" / "Cognos" / "运行数据" / "第一发生场.jsonl"),
            *[
                protected_record(REPO / "web" / ".state" / name)
                for name in ("codex_thread.json", "gemini_thread.json", "gemini_b_thread.json", "deepseek_thread.json", "pi_thread.json")
            ],
        ],
        "known_temporary_dirs": known_temp_dirs(),
        "ports": [
            {"port": port, "label": label, "listening": is_listening(port)}
            for port, label in KNOWN_PORTS.items()
        ],
        "public_access_state": public_state(),
        "worktree": git_summary(),
        "not_scanned": [
            "credentials under deploy/nextcloud-trial/.secrets",
            "database contents",
            "message bodies and runtime thread contents",
        ],
    }


def main() -> int:
    parser = argparse.ArgumentParser(description="Read-only Cognos instance inventory")
    parser.add_argument("--output", type=Path, help="optional JSON report destination")
    args = parser.parse_args()
    report = build_report()
    text = json.dumps(report, ensure_ascii=False, indent=2) + "\n"
    if args.output:
        args.output.parent.mkdir(parents=True, exist_ok=True)
        args.output.write_text(text, encoding="utf-8")
        print(f"wrote {args.output}")
    else:
        print(text, end="")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
