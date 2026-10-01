"""可采用的取材定义：预览纯求值，执行才读取当前项目的真实发生。"""
from __future__ import annotations

from typing import Any
import re
from activity_program import ProgramError, ProgramResult, execute_program


def browse_project_history(records: list[dict[str, Any]], project_id: str, *, before: str | None = None,
                           limit: int = 20, comparison: dict[str, Any] | None = None) -> dict[str, Any]:
    """Browse independently of a keyword hypothesis; cursor uses append-only record identity."""
    if type(limit) is not int or not 1 <= limit <= 20:
        raise ProgramError("浏览条数须为 1 至 20")
    if before is not None and (not isinstance(before, str) or not before):
        raise ProgramError("继续浏览需要有效的原始发生标识")
    eligible = [item for item in records if item.get("project_id") == project_id and not any(
        item.get(flag) for flag in ("activity_operation", "activity_change_request", "activity_acquisition"))]
    end = len(eligible)
    if before is not None:
        index = next((i for i, item in enumerate(eligible) if item["id"] == before), None)
        if index is None:
            raise ProgramError("浏览位置不属于当前项目可浏览的记录")
        end = index
    query = validate_request(comparison) if comparison else None
    output = []
    start = max(0, end - limit)
    for item in reversed(eligible[start:end]):
        kind = "handoff_result" if item.get("handoff_result") else "message"
        matches = None if query is None else (
            re.search(re.escape(query["contains"]), item["content"], re.IGNORECASE) is not None
            and (not query["author"] or query["author"] == item["author"])
            and (query["kind"] == "any" or query["kind"] == kind)
            and (not query["occurrence_id"] or query["occurrence_id"] == item["id"]))
        output.append({"occurrence_id": item["id"], "author": item["author"], "kind": kind,
                       "occurred_at": item.get("occurred_at"), "content": item["content"][:500],
                       "content_truncated": len(item["content"]) > 500, "total_characters": len(item["content"]),
                       "matches_current_filters": matches})
    return {"provider": "project_history_browse", "scope": "project_messages_and_handoff_results",
            "project_id": project_id, "records": output, "eligible_count": len(eligible),
            "returned_count": len(output), "older_count": start,
            "next_before": output[-1]["occurrence_id"] if start and output else None,
            "comparison": query, "note": "筛选命中仅是字面匹配，不代表相关性或是否已被理解；节选可按原始发生标识回读。"}


def read_project_occurrence(records: list[dict[str, Any]], project_id: str,
                            occurrence_id: str, *, start: int = 0, length: int = 6000) -> dict[str, Any]:
    """Read an exact bounded range without applying the current search interpretation."""
    if not isinstance(occurrence_id, str) or not occurrence_id.strip():
        raise ProgramError("回读需要明确的原始发生标识")
    if type(start) is not int or start < 0 or type(length) is not int or not 1 <= length <= 6000:
        raise ProgramError("回读起点须为非负整数，长度须为 1 至 6000")
    item = next((item for item in records
                 if item.get("id") == occurrence_id and item.get("project_id") == project_id), None)
    if item is None:
        raise ProgramError("当前项目中没有这条原始发生")
    content = item["content"]
    if start > len(content):
        raise ProgramError("回读起点超过原文长度")
    end = min(start + length, len(content))
    return {"provider": "project_occurrence", "project_id": project_id,
            "occurrence_id": occurrence_id, "author": item["author"],
            "occurred_at": item.get("occurred_at"), "content": content[start:end],
            "excerpt_start": start, "excerpt_end": end, "total_characters": len(content),
            "content_truncated": start != 0 or end != len(content),
            "next_start": end if end < len(content) else None}


def validate_request(value: Any) -> dict[str, Any]:
    if not isinstance(value, dict) or set(value) - {"provider", "contains", "author", "kind", "limit", "occurrence_id"}:
        raise ProgramError("取材结果需要明确的 provider、contains 等参数，不支持额外字段")
    if value.get("provider") != "project_history":
        raise ProgramError("当前可用取材通路是 project_history（当前项目的真实发言与结果）")
    result = {"provider": "project_history", "contains": value.get("contains", ""),
              "author": value.get("author", ""), "kind": value.get("kind", "any"),
              "limit": value.get("limit", 10), "occurrence_id": value.get("occurrence_id", "")}
    for key in ("contains", "author", "occurrence_id"):
        if not isinstance(result[key], str) or len(result[key]) > 1000:
            raise ProgramError(f"{key} 必须是不超过 1000 字符的文字")
    if result["kind"] not in ("any", "message", "handoff_result"):
        raise ProgramError("kind 只支持 any、message、handoff_result")
    if type(result["limit"]) is not int or not 1 <= result["limit"] <= 20:
        raise ProgramError("取材条数须为 1 至 20")
    return result


def compile_acquisition(state: dict[str, Any]) -> tuple[ProgramResult, dict[str, Any]]:
    adopted = state.get("source_adopted")
    if not adopted:
        raise ProgramError("尚未采用取材方式")
    result = execute_program(state, {"op": "call", "document": adopted["document"], "version": adopted["version"]},
                             program_versions=adopted.get("program_versions", {}))
    return result, validate_request(result.value)


def collect_project_history(records: list[dict[str, Any]], project_id: str,
                            request: dict[str, Any]) -> dict[str, Any]:
    """按字面包含、作者及结果类型筛选；不把检索理解为语义判断。"""
    request = validate_request(request)
    pattern = re.compile(re.escape(request["contains"]), re.IGNORECASE)
    found = []
    for item in reversed(records):
        if item.get("project_id") != project_id:
            continue
        if any(item.get(flag) for flag in ("activity_operation", "activity_change_request", "activity_acquisition")):
            continue
        kind = "handoff_result" if item.get("handoff_result") else "message"
        if request["kind"] != "any" and request["kind"] != kind:
            continue
        if request["author"] and item["author"] != request["author"]:
            continue
        if request["occurrence_id"] and item["id"] != request["occurrence_id"]:
            continue
        if pattern.search(item["content"]) is None:
            continue
        found.append(item)
    output = []
    for item in found[:request["limit"]]:
        content = item["content"]
        # 始终指明节选；完整原始发生仍由 ID 引用，不冒充已读全文。
        position = pattern.search(content).start()
        start = max(0, position - 1000) if len(content) > 6000 else 0
        excerpt = content[start:start + 6000]
        output.append({"occurrence_id": item["id"], "author": item["author"], "occurred_at": item.get("occurred_at"),
                       "kind": "handoff_result" if item.get("handoff_result") else "message",
                       "content": excerpt, "content_truncated": len(excerpt) != len(content), "excerpt_start": start})
    return {"provider": "project_history", "project_id": project_id, "request": request,
            "matched_count": len(found), "returned_count": len(output), "more_matches": len(found) > len(output),
            "records": output}
