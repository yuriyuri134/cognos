"""B3：Generic Manual Import。

给没有可靠本地 API 的来源一个统一入口。
raw/imported material -> source identity -> archive -> registry。
绝不 append 到第一发生场.jsonl。
"""

from __future__ import annotations

import argparse
import hashlib
import json
import shutil
import re
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

from boundary.registry import SourceRegistry, utc_now_iso

SCHEMA = "cognos.boundary.manual_import.v1"


class ManualImportError(RuntimeError):
    """手工导入失败。"""


def sha256_short(path: Path, length: int = 16) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for chunk in iter(lambda: stream.read(65536), b""):
            digest.update(chunk)
    return digest.hexdigest()[:length]


def import_file(
    *,
    source_id: str,
    source_type: str,
    path: Path,
    archive_root: Path,
    registry: SourceRegistry,
    label: str | None = None,
    metadata: dict[str, Any] | None = None,
) -> dict[str, Any]:
    path = Path(path).expanduser()
    if not path.is_file():
        raise ManualImportError(f"输入文件不存在：{path}")
    source_id = source_id.strip()
    if not source_id:
        raise ValueError("source_id 不能为空")
    if (
        Path(source_id).is_absolute()
        or source_id in {".", ".."}
        or "/" in source_id
        or "\\\\" in source_id
        or ".." in Path(source_id).parts
        or not re.fullmatch(r"[A-Za-z0-9._-]+", source_id)
    ):
        raise ValueError("source_id 必须是单一安全路径片段")

    archive_root = Path(archive_root).expanduser().resolve()
    digest = sha256_short(path)
    archive_dir = (archive_root / "manual" / source_id / digest).resolve()
    if not archive_dir.is_relative_to(archive_root):
        raise ManualImportError("归档路径越出 archive_root")
    archive_dir.mkdir(parents=True, exist_ok=True)
    raw_path = archive_dir / ("raw" + (path.suffix or ".bin"))
    projection_path = archive_dir / "import.meta.json"

    is_new = not raw_path.exists()
    if is_new:
        shutil.copy2(path, raw_path)
    elif sha256_short(raw_path) != digest:
        raise ManualImportError("已有归档内容与本次来源不同，拒绝覆盖")

    now = utc_now_iso()
    projection = {
        "schema": SCHEMA,
        "source_id": source_id,
        "source_type": source_type,
        "imported_at": now,
        "label": label,
        "raw_path": str(path.resolve()),
        "archive_raw_path": str(raw_path.resolve()),
        "sha256_prefix": digest,
        "metadata": metadata or {},
        "note": "这是外部来源的归档/投影，不自动进入第一发生场；只有被显式面对后才留下 occurrence。",
    }
    temporary = projection_path.with_suffix(projection_path.suffix + ".tmp")
    temporary.write_text(json.dumps(projection, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    temporary.replace(projection_path)

    source = registry.upsert_source(
        source_id=source_id,
        source_type=source_type,
        native_location=str(path.resolve()),
        status="available",
        extra={
            "last_import_sha256_prefix": digest,
            "last_import_at": now,
            "last_import_ref": str(projection_path.resolve()),
        },
    )
    return {
        "source": source,
        "projection": projection,
        "new": is_new,
    }


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="B3 Generic Manual Import")
    parser.add_argument("file", type=Path)
    parser.add_argument("--source-id", required=True)
    parser.add_argument("--source-type", default="manual-import")
    parser.add_argument("--label", default=None)
    parser.add_argument("--archive-root", type=Path, required=True)
    parser.add_argument("--registry-path", type=Path, default=None)
    parser.add_argument("--json", action="store_true")
    args = parser.parse_args(argv)

    registry_path = args.registry_path or (args.archive_root / "registry.json")
    try:
        result = import_file(
            source_id=args.source_id,
            source_type=args.source_type,
            path=args.file,
            archive_root=args.archive_root,
            registry=SourceRegistry(registry_path),
            label=args.label,
        )
    except (ManualImportError, OSError, ValueError) as exc:
        print(f"error: {exc}")
        return 1
    if args.json:
        print(json.dumps(result, ensure_ascii=False, indent=2))
    else:
        print(f"imported={result['projection']['source_id']} new={result['new']}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
