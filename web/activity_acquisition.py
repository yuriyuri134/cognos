"""可采用的取材定义：预览纯求值，执行才读取当前项目的真实发生。"""
from __future__ import annotations

from typing import Any
import re
from activity_program import ProgramError, ProgramResult, execute_program


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
