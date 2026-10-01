"""Activity context assembly for the first observable runtime packet."""

from __future__ import annotations

import json
from dataclasses import asdict
from typing import Any

from activity_program import ProgramError, compile_activity, document_version
from activity_store import material_catalog
from cognitive_runtime import build_activity_view, build_cognitive_conditions


def assemble_activity_context(
    activity: dict[str, Any], content: str, results: list[dict[str, Any]], *,
    project: dict[str, Any] | None = None, frame: list[dict[str, Any]] | None = None,
    recent: list[dict[str, Any]] | None = None, moves: list[dict[str, Any]] | None = None,
    formation_history: list[dict[str, Any]] | None = None,
    tension_history: list[dict[str, Any]] | None = None,
    user_occurrence_id: str | None = None, initiator: str = "",
    participant: dict[str, Any] | None = None,
    runtime_meta: dict[str, Any] | None = None,
) -> dict[str, Any]:
    """Build named, serialisable context buckets without changing activity state."""
    state = activity["state"]
    try:
        compiled = asdict(compile_activity(state))
        compiled["status"] = "ok"
    except ProgramError as exc:
        compiled = {"status": "failed", "error": str(exc)}
    adopted = state.get("adopted")
    definition = document_version(state, adopted["document"], adopted["version"]) if adopted else None
    source_adopted = state.get("source_adopted")
    source_context = None
    if source_adopted:
        source_context = {
            "adopted": source_adopted,
            "definition": document_version(state, source_adopted["document"], source_adopted["version"]),
        }
        try:
            from activity_acquisition import compile_acquisition
            _, source_context["request"] = compile_acquisition(state)
            source_context["status"] = "ready"
        except ProgramError as exc:
            source_context.update(status="failed", error=str(exc))
    situation = state.get("situation")
    catalog = material_catalog(state)
    recent_entries = recent or []
    frame_entries = frame or []
    encounter_entries = results or []
    active_reads = compiled.get("reads", []) if compiled.get("status") == "ok" else []
    active_entries: list[dict[str, Any]] = []
    for ref in active_reads:
        try:
            material = document_version(state, ref["document"], ref["version"])
            entry = {
                "id": material["id"], "title": material["title"],
                "format": material["format"], "version": material["version"],
                "immutable": state["documents"][material["id"]]["immutable"],
                "occurrence_id": material.get("occurrence_id"),
                "source_author": material.get("source_author"),
                "included_by": "compiled_activity",
            }
            if adopted is None:
                entry["body"] = material["body"]
            else:
                # Execution dependencies are provenance, not a request to inject
                # every dependency body again after slice/select/replace.
                entry["body_scope"] = "dependency_reference_only"
                entry["read_operation"] = {"type": "read", "document": material["id"],
                                           "version": material["version"]}
            active_entries.append(entry)
        except (ProgramError, KeyError, TypeError):
            # 编排失败已经进入 compiled；这里不把无法读取的引用伪装成材料。
            continue
    activity_view = build_activity_view(
        state, formation_history=formation_history, tension_history=tension_history)
    historical_formations = activity_view["formation_history"]
    formations = list(activity_view["formations"]["active"])
    dormant_formations = list(activity_view["formations"]["dormant"])
    # The single legacy slot is an echo of model output once event history exists.
    # Do not reintroduce a suspended interpretation through that compatibility slot.
    if historical_formations and activity_view["projection"]["situation_is_compatibility_mirror"]:
        situation = formations[-1] if formations else None
    else:
        # An explicit user/legacy edit is independent of model formations.
        if situation is not None:
            formations.append({"id": "legacy-situation", "revision": activity["revision"],
                               "text": situation.get("text", ""), "refs": situation.get("refs", []),
                               "source": "current_state"})
    tensions = list(activity_view["tensions"]["open"])
    moves_entries = [
        {"id": item["id"], "proposer": item["proposer"],
         "proposer_participant_id": item.get("proposer_participant_id"),
         "occurrence_id": item.get("occurrence_id"), "basis_revision": item["basis_revision"],
         "intent": item["intent"], "status": item["state"].get("status"),
         "formation_refs": item["state"].get("formation_refs", []),
         "tension_refs": item["state"].get("tension_refs", []),
         "results": item["state"].get("results", [])}
        for item in (moves or [])
    ]
    context: dict[str, Any] = {
        # Legacy keys remain stable for current runtime fixtures.
        "activity_id": activity["id"], "revision": activity["revision"],
        "user_message": content, "focus": state["focus"], "project": project,
        "material_catalog": catalog, "adopted": adopted,
        "adopted_definition": definition, "compiled": compiled,
        "acquisition": source_context,
        "situation": ({"revision": activity["revision"], "text": situation.get("text", ""),
                        "refs": situation.get("refs", [])} if situation is not None else None),
        "moves": moves_entries, "operation_results": encounter_entries,
        "frame_material": frame_entries, "recent_activity": recent_entries,
        # Packet A buckets.
        "schema_version": 1,
        "active_materials": {"catalog": catalog, "entries": active_entries,
                             "selection": {"mode": "compiled_activity", "reads": active_reads,
                                           "body_policy": "compiled_result_with_readable_sources" if adopted else "all_materials",
                                           "note": "采用组合后，依赖目录不额外注入全文；原文可按版本回读。近期结果和用户输入仍独立进入。"},
                             "adopted": adopted,
                             "adopted_definition": definition, "compiled": compiled,
                             "acquisition": source_context},
        "formations": formations,
        "dormant_formations": dormant_formations,
        "formation_projection": {"policy": activity_view["projection"]["policy"],
                                 "event_count": activity_view["projection"]["formation_event_count"],
                                 "note": "停用不等于判假；历史版本仍保留在活动事件中"},
        "tensions": tensions,
        "resolved_tensions": list(activity_view["tensions"]["resolved"]),
        "activity_view": activity_view,
        "cognitive_conditions": build_cognitive_conditions(
            formations=activity_view["formations"]["active"], tensions=tensions,
            encounters=encounter_entries, moves=moves_entries, projected=True),
        "recent_encounters": encounter_entries,
        "retrieved_history": {"frame_material": frame_entries, "recent_activity": recent_entries},
        "affordances": [
            {"type": "read", "requires_arguments": True},
            {"type": "read_occurrence", "requires_arguments": True,
             "notes": "按当前项目原始发生 ID 和字符区间回读；不按当前理解筛选；可依 next_start 继续"},
            {"type": "inspect_activities", "requires_arguments": False},
            {"type": "browse_history", "requires_arguments": False,
             "notes": "不按当前取材条件过滤，分页浏览项目原话；节选可回读全文"},
            {"type": "import_activity", "requires_arguments": True,
             "notes": "从同项目另一活动的明确版本带入材料或构造依赖；不自动采用"},
            {"type": "evaluate", "requires_arguments": True},
            {"type": "change", "requires_arguments": True},
            {"type": "create", "requires_arguments": True},
            {"type": "acquire", "requires_arguments": False},
            {"type": "retrieve", "requires_arguments": True,
             "notes": "可提交一至八个查询候选；结果作为外部材料进入下一轮"},
            {"type": "ask_user", "requires_arguments": True},
        ],
        "user_input": {"content": content, "occurrence_id": user_occurrence_id,
                        "initiator": initiator},
        "environment": {"activity_id": activity["id"], "activity_revision": activity["revision"],
                         "project": project},
        "participant": participant or {}, "runtime": runtime_meta or {},
        "excluded_history": {"policy": "explicit_retrieval_only", "omitted_counts": {}},
        "assembly": {"reasons": ["legacy_activity_context", "named_context_buckets"],
                     "source_refs": [{"document": item["id"], "version": item["version"]}
                                     for item in catalog]},
    }
    return context


PROMPT_PROFILES = {"baseline", "minimal", "full"}


def project_model_context(context: dict[str, Any], profile: str = "full") -> dict[str, Any]:
    """Project the same stored context into an explicit experimental prompt profile.

    The source context remains the full observable snapshot.  Profiles only
    change what is serialized for the model so ablation runs can keep the same
    underlying Activity, tools, and recoverable information.
    """
    if profile not in PROMPT_PROFILES:
        raise ValueError(f"unknown prompt profile: {profile}")

    # Keep the full ActivityView in snapshots/API for observability, but do not
    # send the same cognitive state to the model three times.  The prompt uses
    # the current top-level formations/tensions as its one state projection;
    # cognitive_conditions contributes only genuinely derived conditions.
    model_context = dict(context)
    model_context.pop("activity_view", None)
    conditions = dict(model_context.get("cognitive_conditions") or {})
    conditions.pop("formations", None)
    conditions.pop("tensions", None)
    model_context["cognitive_conditions"] = conditions

    if profile == "full":
        return model_context

    # Minimal keeps the proven runtime substrate (materials/results/provenance,
    # actual selection, history/import affordances) but removes the cognitive
    # structures whose model-facing value is still a hypothesis.
    for key in (
        "formations", "dormant_formations", "formation_projection",
        "tensions", "resolved_tensions", "cognitive_conditions",
    ):
        model_context.pop(key, None)
    if profile == "minimal":
        return model_context

    # Baseline receives the same reachable pool and operation affordances, but
    # without Cognos' adopted/compiled organization or move-state projection.
    # A neutral current_material_view prevents the baseline from losing the
    # actual content that the full/minimal condition can currently see.
    neutral_materials = {
        "catalog": context.get("material_catalog", []),
        "current_material_view": context.get("compiled"),
        "note": "全部来源仍可通过 material_catalog 与 read 操作按版本读取。",
    }
    baseline_keys = (
        "activity_id", "revision", "user_message", "focus", "project",
        "operation_results", "frame_material", "recent_activity",
        "recent_encounters", "retrieved_history", "affordances", "user_input",
        "environment", "participant", "runtime", "excluded_history",
    )
    baseline = {key: model_context[key] for key in baseline_keys if key in model_context}
    baseline["materials"] = neutral_materials
    return baseline


def render_activity_prompt(protocol: str, context: dict[str, Any], *, profile: str = "full") -> str:
    model_context = project_model_context(context, profile)
    return protocol + "\n\n本次处境（内容材料）：\n" + json.dumps(
        model_context, ensure_ascii=False, allow_nan=False)
