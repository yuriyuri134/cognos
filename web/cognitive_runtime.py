"""认知运行时的最小实验层。

这里不定义最终的认知本体，也不把“张力”解释成一个分数。它只把已经在
Activity 事件中出现的形成、未解决内容和遭遇整理成下一轮可回看的生成条件，
让这些内容有机会实际改变后续活动。
"""

from __future__ import annotations

import hashlib
import json
from typing import Any


def _bounded(items: list[dict[str, Any]] | None, limit: int = 40) -> list[dict[str, Any]]:
    return [dict(item) for item in (items or [])[-limit:] if isinstance(item, dict)]


def collect_activity_updates(runs: list[dict[str, Any]]) -> tuple[list[dict[str, Any]], list[dict[str, Any]]]:
    """Use the same event identities for model context and the human-facing view."""
    formations, tensions = [], []
    for run in runs:
        for call_index, event in enumerate(run.get("events", [])):
            participant = event.get("context", {}).get("participant", {})
            origin = {"run_id": run["id"], "call_index": call_index, "source": "activity_event",
                      "participant": participant, "occurrence_id": event.get("occurrence_id"),
                      "context_snapshot_id": event.get("context_snapshot_id")}
            for index, formation in enumerate(event.get("formation_updates", [])):
                event_id = f"{run['id']}:{call_index}:{index}"
                formations.append({**formation, **origin, "id": formation.get("id") or event_id,
                                   "event_id": event_id})
            for index, tension in enumerate(event.get("tension_updates", [])):
                event_id = f"{run['id']}:{call_index}:tension:{index}"
                tensions.append({**tension, **origin,
                                 "id": tension.get("id") or event_id, "event_id": event_id})
    return formations, tensions


def activity_view_from_runs(runs: list[dict[str, Any]],
                            activity_state: dict[str, Any] | None = None) -> dict[str, Any]:
    history, tensions = collect_activity_updates(runs)
    return build_activity_view(activity_state or {}, formation_history=history, tension_history=tensions)


def formation_view(runs: list[dict[str, Any]],
                   activity_state: dict[str, Any] | None = None) -> dict[str, Any]:
    view = activity_view_from_runs(runs, activity_state)
    return {"scope": "current_activity", "active": view["formations"]["active"],
            "dormant": view["formations"]["dormant"], "history": view["formation_history"]}


def project_updates(items: list[dict[str, Any]] | None, *, default_status: str) -> list[dict[str, Any]]:
    """Replay explicit ID updates; never infer identity from similar wording.

    This is a disposable current-state view. Input events remain unchanged and
    each view carries event references back to the history it was built from.
    Unknown legacy statuses remain visible; only explicit dormant/resolved
    values remove items from their respective active buckets.
    """
    current: dict[str, dict[str, Any]] = {}
    for index, event in enumerate(items or []):
        ident = event.get("id") or event.get("event_id") or f"unidentified-{index}"
        previous = current.pop(ident, {})
        refs = list(previous.get("revision_event_ids", []))
        if event.get("event_id") and event["event_id"] not in refs:
            refs.append(event["event_id"])
        current[ident] = {
            "text": "", "refs": [], "status": default_status,
            **previous, **event, "id": ident, "revision_event_ids": refs,
        }
    return list(current.values())


def build_activity_view(activity_state: dict[str, Any], *,
                        formation_history: list[dict[str, Any]] | None = None,
                        tension_history: list[dict[str, Any]] | None = None) -> dict[str, Any]:
    """Build the one disposable replay view consumed by higher layers.

    Run events and Activity state remain authoritative.  This view is never
    persisted: it only centralises the rules for replaying Formation/Tension
    updates and distinguishing an independent legacy/user situation from the
    old Formation compatibility mirror.
    """
    formation_events = [dict(item) for item in (formation_history or [])]
    tension_events = [dict(item) for item in (tension_history or [])]
    formation_current = project_updates(formation_events, default_status="active")
    tension_current = project_updates(tension_events, default_status="open")
    situation = activity_state.get("situation") if isinstance(activity_state, dict) else None
    independent_situation = None
    if isinstance(situation, dict) and not situation.get("formation_projection"):
        independent_situation = {
            "text": situation.get("text", ""),
            "refs": list(situation.get("refs", [])),
        }
    return {
        "schema_version": 1,
        "formation_history": formation_events,
        "formations": {
            "active": [item for item in formation_current if item.get("status") != "dormant"],
            "dormant": [item for item in formation_current if item.get("status") == "dormant"],
        },
        "tension_history": tension_events,
        "tensions": {
            "open": [item for item in tension_current if item.get("status", "open") != "resolved"],
            "resolved": [item for item in tension_current if item.get("status", "open") == "resolved"],
        },
        "independent_situation": independent_situation,
        "projection": {
            "policy": "latest_explicit_id_update",
            "formation_event_count": len(formation_events),
            "tension_event_count": len(tension_events),
            "situation_is_compatibility_mirror": bool(
                isinstance(situation, dict) and situation.get("formation_projection")
            ),
        },
    }


def build_cognitive_conditions(*, formations: list[dict[str, Any]] | None = None,
                               tensions: list[dict[str, Any]] | None = None,
                               encounters: list[dict[str, Any]] | None = None,
                               moves: list[dict[str, Any]] | None = None,
                               projected: bool = False) -> dict[str, Any]:
    """Build a replayable, deliberately weak description of current generation conditions.

    ``unassimilated_candidates`` is intentionally a candidate bucket: the runtime
    does not claim to know whether an encounter was understood. It keeps recent
    encounters visible so a current formation cannot silently monopolise context.
    """
    # Reduce before bounding: a later suspension must not resurrect an old item.
    formation_source = formations if projected else project_updates(formations, default_status="active")
    tension_source = tensions if projected else project_updates(tensions, default_status="open")
    formation_items = _bounded([item for item in (formation_source or []) if item.get("status") != "dormant"])
    tension_items = _bounded(tension_source)
    encounter_items = _bounded(encounters, 20)
    move_items = _bounded(moves, 20)
    unresolved = [item for item in tension_items if item.get("status", "open") != "resolved"]
    candidate_unassimilated = [
        {"index": index, "encounter": item}
        for index, item in enumerate(encounter_items)
        # Failure can itself contradict a formation; it is still an encounter.
    ]
    channels = [
        {"type": "raw_material", "reason": "原始材料可以重新进入，不由当前形成删改"},
        {"type": "user_or_participant", "reason": "参与者的新表达可以改变当前组织"},
        {"type": "external_world", "reason": "外部读取、检索和执行结果可以制造新差异"},
        {"type": "construction", "reason": "模型或程序构造出的中间材料可以被再次面对"},
        {"type": "alternate_formation", "reason": "多个未统一的形成可以并存"},
    ]
    body = {
        "schema_version": 1,
        "formations": formation_items,
        "tensions": unresolved,
        "unassimilated_candidates": candidate_unassimilated,
        "recent_moves": move_items,
        "reopening_channels": channels,
        "rule": "形成可以组织下一轮，但不能穷尽下一轮；遭遇不自动等于已被理解",
    }
    canonical = json.dumps(body, ensure_ascii=False, sort_keys=True, allow_nan=False)
    body["condition_fingerprint"] = hashlib.sha256(canonical.encode("utf-8")).hexdigest()
    return body
