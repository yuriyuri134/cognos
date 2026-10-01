"""Copy a versioned material or executable construction with its actual dependencies."""
from __future__ import annotations

import copy
import uuid
from typing import Any

from activity_program import ProgramError, document_version, execute_program


def discover_experience_candidates(store: Any, current: dict[str, Any], *, limit: int = 20) -> list[dict[str, Any]]:
    """Expose prior actually-used material without auto-importing or auto-adopting it.

    This is deliberately a browse surface rather than a learned memory layer.  A
    candidate must have an observable use trace in its source activity: it was
    adopted as a construction/source definition, or it was produced/returned by
    an executed activity step.  Only metadata enters the catalog; source bodies
    remain behind an explicit import/read boundary.
    """
    if type(limit) is not int or not 1 <= limit <= 100:
        raise ProgramError("经验候选数量必须在 1 至 100 之间")

    project_id = current["project_id"]
    candidates: list[dict[str, Any]] = []
    seen: set[tuple[str, str, int, str]] = set()

    for summary in store.list(project_id):
        if summary["id"] == current["id"]:
            continue
        source = store.get(summary["id"])
        if source is None:
            continue
        state = source["state"]
        evidence_by_document: dict[str, list[dict[str, Any]]] = {}

        for slot, role in (("adopted", "active_construction"),
                           ("source_adopted", "active_acquisition_definition")):
            adopted = state.get(slot)
            if adopted and isinstance(adopted.get("document"), str):
                evidence_by_document.setdefault(adopted["document"], []).append({
                    "type": role,
                    "version": adopted.get("version"),
                })

        for run in store.runs(source["id"]):
            for event in run.get("events", []):
                for result in event.get("results", []):
                    document_id = result.get("document")
                    if isinstance(document_id, str) and document_id in state.get("documents", {}):
                        evidence_by_document.setdefault(document_id, []).append({
                            "type": "actual_result",
                            "result_type": result.get("type"),
                            "status": result.get("status", "completed"),
                            "occurrence_id": result.get("result_occurrence_id") or event.get("result_occurrence_id"),
                        })

        for document_id, evidence in evidence_by_document.items():
            document = state.get("documents", {}).get(document_id)
            if not document or not document.get("versions"):
                continue
            latest_version = len(document["versions"])
            adopted_versions = [item.get("version") for item in evidence
                                if item["type"] in {"active_construction", "active_acquisition_definition"}
                                and type(item.get("version")) is int]
            version = adopted_versions[-1] if adopted_versions else latest_version
            kind = "construction" if adopted_versions else "material"
            key = (source["id"], document_id, version, kind)
            if key in seen:
                continue
            seen.add(key)
            candidates.append({
                "source_activity": source["id"],
                "source_title": state.get("title", summary.get("title", "")),
                "source_revision": source["revision"],
                "document": document_id,
                "document_title": document.get("title", ""),
                "version": version,
                "format": document.get("format"),
                "kind": kind,
                "evidence": evidence[-6:],
                "applicability": "unverified_candidate",
                "automatic_adoption": False,
            })
            if len(candidates) >= limit:
                return candidates
    return candidates


def prepare_transfer(source: dict[str, Any], document: str, *, version: int | None = None,
                     kind: str = "material", note: str = "") -> dict[str, Any]:
    if kind not in {"material", "construction"}:
        raise ProgramError("带入方式须为 material 或 construction")
    if not isinstance(note, str) or len(note) > 4000:
        raise ProgramError("适用说明须为不超过 4000 字的文字")
    state = source["state"]
    root = document_version(state, document, version)
    bindings = {}
    for slot in ("adopted", "source_adopted"):
        adopted = state.get(slot)
        if adopted and (adopted["document"], adopted["version"]) == (document, root["version"]):
            bindings = adopted.get("program_versions", {})
            break
    evaluation = None
    if kind == "construction":
        evaluation = execute_program(state, {"op": "call", "document": document, "version": root["version"]},
                                     program_versions=bindings)
        bindings = evaluation.program_versions
    operations, origins, mapping = [], [], {}

    def include(ident: str, number: int | None, role: str) -> str:
        found = document_version(state, ident, number)
        key = (ident, found["version"], role)
        if key in mapping:
            return mapping[key]
        if len(mapping) >= 39:
            raise ProgramError("构造依赖超过本次带入范围，请拆分材料")
        target = uuid.uuid4().hex
        mapping[key] = target
        body = rewrite(found["body"]) if role == "program" else copy.deepcopy(found["body"])
        operations.append({"action": "add_document", "id": target, "title": found["title"],
                           "format": found["format"], "body": body, "immutable": role != "program"})
        origins.append({"document": target, "source_document": ident, "source_version": found["version"],
                        "source_occurrence_id": found.get("occurrence_id"),
                        "source_author": found.get("source_author"), "role": role})
        return target

    def rewrite(node: dict[str, Any]) -> dict[str, Any]:
        result = copy.deepcopy(node)
        op = node.get("op")
        if op in {"read", "call"}:
            number = node.get("version")
            if op == "call" and number is None:
                number = bindings.get(node["document"])
            result["document"] = include(node["document"], number, "program" if op == "call" else "data")
            result["version"] = 1
        elif op in {"select", "slice", "replace", "render"}:
            result["input"] = rewrite(node["input"])
        elif op in {"join", "list"}:
            result["inputs"] = [rewrite(item) for item in node["inputs"]]
        elif op == "object":
            result["fields"] = {key: rewrite(value) for key, value in node["fields"].items()}
        elif op == "calculate":
            result["left"], result["right"] = rewrite(node["left"]), rewrite(node["right"])
        # literal values are data even if they resemble operations or references.
        return result

    target = include(document, root["version"], "program" if kind == "construction" else "data")
    manifest = {"source_activity": source["id"], "source_revision": source["revision"],
                "source_project": source["project_id"], "root_document": target, "kind": kind,
                "note": note, "note_status": "participant_account_not_verified_applicability",
                "origins": origins, "automatic_adoption": False}
    manifest_id = uuid.uuid4().hex
    operations.append({"action": "add_document", "id": manifest_id, "title": "带入来源与适用说明",
                       "format": "json", "body": manifest, "immutable": True})
    return {"operations": operations, "manifest": manifest, "document": target,
            "manifest_document": manifest_id}
