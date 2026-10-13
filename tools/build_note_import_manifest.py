"""Build a redacted, read-only manifest for the first Cognos note-import sample.

This tool never copies or changes source content. It records source identity,
metadata, hashes, and explicit Obsidian wikilinks so a later importer can be
verified against the original files.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import re
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

WIKILINK_RE = re.compile(r"\[\[([^\]]+)\]\]")
SAMPLE_SOURCES = (
    ("Obsidian Vault", Path(r"C:\Users\lnp\Documents\Obsidian Vault\00 Inbox\项目方向清单-待展开.md")),
    ("Obsidian Vault", Path(r"C:\Users\lnp\Documents\Obsidian Vault\10 Projects\Spark-核心设计哲学.md")),
    ("ChatGPT project", Path(r"C:\Users\lnp\Documents\ChatGPT\New project\Cognos-给pi-agent的执行交接.md")),
    ("Obsidian Vault", Path(r"C:\Users\lnp\Documents\Obsidian Vault\10 Projects\_原料_异轴思想.json")),
    ("Cognos repository", Path(r"C:\Users\lnp\Documents\Playground\cognos\docs\Cognos-空间与认知架构-工作稿.md")),
)


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def parse_frontmatter(text: str) -> dict[str, str]:
    if not text.startswith("---\n"):
        return {}
    closing = text.find("\n---", 4)
    if closing < 0:
        return {}
    result: dict[str, str] = {}
    for line in text[4:closing].splitlines():
        if ":" not in line:
            continue
        key, value = line.split(":", 1)
        key = key.strip()
        value = value.strip()
        if key:
            result[key] = value
    return result


def source_path(root_name: str, path: Path) -> str:
    roots = {
        "Obsidian Vault": Path(r"C:\Users\lnp\Documents\Obsidian Vault"),
        "ChatGPT project": Path(r"C:\Users\lnp\Documents\ChatGPT\New project"),
        "Cognos repository": Path(r"C:\Users\lnp\Documents\Playground\cognos"),
    }
    try:
        return path.relative_to(roots[root_name]).as_posix()
    except ValueError:
        return path.name


def build_record(root_name: str, path: Path) -> dict[str, Any]:
    record: dict[str, Any] = {
        "source_root": root_name,
        "source_path": source_path(root_name, path),
        "source_kind": path.suffix.lower().lstrip(".") or "text",
        "title": path.stem,
        "import_status": "missing",
    }
    if not path.is_file():
        return record

    raw = path.read_bytes()
    text = raw.decode("utf-8", errors="replace")
    stat = path.stat()
    record.update(
        {
            "source_id": hashlib.sha256((root_name + "\0" + record["source_path"]).encode()).hexdigest(),
            "content_hash": hashlib.sha256(raw).hexdigest(),
            "source_mtime": datetime.fromtimestamp(stat.st_mtime, timezone.utc).isoformat(),
            "byte_length": len(raw),
            "character_length": len(text),
            "frontmatter": parse_frontmatter(text) if path.suffix.lower() == ".md" else {},
            "links": sorted(set(WIKILINK_RE.findall(text))) if path.suffix.lower() == ".md" else [],
            "participants": [],
            "import_status": "imported",
        }
    )
    return record


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--source", action="append", type=Path, default=[])
    args = parser.parse_args()

    selected = SAMPLE_SOURCES
    if args.source:
        selected = tuple(("custom", path) for path in args.source)
    records = [build_record(root, path) for root, path in selected]
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(
        json.dumps(
            {
                "manifest_version": 1,
                "generated_at": datetime.now(timezone.utc).isoformat(),
                "read_only": True,
                "content_included": False,
                "records": records,
            },
            ensure_ascii=False,
            indent=2,
        )
        + "\n",
        encoding="utf-8",
    )
    missing = sum(record["import_status"] == "missing" for record in records)
    print(f"wrote {args.output} records={len(records)} missing={missing}")
    return 0 if missing == 0 else 2


if __name__ == "__main__":
    raise SystemExit(main())
