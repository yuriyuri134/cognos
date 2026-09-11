"""把活动内的可修改组合定义实际用于取材和变换，不裁定材料的语义。"""

from __future__ import annotations

import copy
import json
import math
from dataclasses import dataclass
from typing import Any


class ProgramError(ValueError):
    """组合无法执行；不能将此状态当成认识完成或空结果。"""


def render_value(value: Any) -> str:
    if isinstance(value, str):
        return value
    return json.dumps(value, ensure_ascii=False, indent=2, allow_nan=False)


@dataclass(frozen=True)
class ProgramResult:
    value: Any
    text: str
    reads: list[dict[str, Any]]
    steps: int
    program_versions: dict[str, int]


def document_version(state: dict[str, Any], document_id: str, version: int | None = None) -> dict[str, Any]:
    """只从同一活动快照取值，避免一次输入混入多个并发时刻。"""
    if not isinstance(document_id, str):
        raise ProgramError("文档标识必须是文字")
    document = state.get("documents", {}).get(document_id)
    if document is None:
        raise ProgramError(f"文档不存在：{document_id}")
    versions = document["versions"]
    number = len(versions) if version is None else version
    if type(number) is not int or not 1 <= number <= len(versions):
        raise ProgramError(f"文档版本不存在：{document_id}@{number}")
    saved = versions[number - 1]
    return {"id": document_id, "title": document["title"], "format": document["format"],
            "version": number, "body": copy.deepcopy(saved["body"]),
            "modified_by": saved["actor"], "occurrence_id": saved.get("occurrence_id"),
            "source_author": saved.get("source_author")}


def execute_program(state: dict[str, Any], expression: Any, *, max_steps: int = 2048,
                    max_output_chars: int = 200_000,
                    program_versions: dict[str, int] | None = None) -> ProgramResult:
    """解释有限操作，不执行 Python、shell 或来自材料的指令。"""
    reads: list[dict[str, Any]] = []
    calls: list[tuple[str, int]] = []
    steps = 0
    resolved_programs = dict(program_versions or {})

    def read(node: dict[str, Any]) -> dict[str, Any]:
        ident = node.get("document")
        if not isinstance(ident, str):
            raise ProgramError("读取需要文档标识")
        found = document_version(state, ident, node.get("version"))
        ref = {"document": found["id"], "version": found["version"]}
        if ref not in reads:
            reads.append(ref)
        return found

    def visit(node: Any, depth: int = 0) -> Any:
        nonlocal steps
        steps += 1
        if steps > max_steps or depth > 32:
            raise ProgramError("组合超出本次执行范围；尚未完成")
        if not isinstance(node, dict):
            raise ProgramError("每个组合步骤必须是对象")
        operation = node.get("op")

        def child(name: str) -> Any:
            if name not in node:
                raise ProgramError(f"{operation} 缺少 {name}")
            return visit(node[name], depth + 1)

        if operation == "literal":
            if "value" not in node:
                raise ProgramError("literal 缺少 value")
            value = copy.deepcopy(node["value"])
        elif operation == "read":
            value = read(node)["body"]
        elif operation == "call":
            call_node = dict(node)
            ident = call_node.get("document")
            if not isinstance(ident, str):
                raise ProgramError("调用需要文档标识")
            if call_node.get("version") is None and ident in resolved_programs:
                call_node["version"] = resolved_programs[ident]
            found = read(call_node)
            if node.get("version") is None:
                resolved_programs[ident] = found["version"]
            if found["format"] != "json":
                raise ProgramError("被调用的组合定义必须是 JSON 文档")
            identity = (found["id"], found["version"])
            if identity in calls:
                raise ProgramError("组合定义出现循环调用")
            calls.append(identity)
            try:
                value = visit(found["body"], depth + 1)
            finally:
                calls.pop()
        elif operation == "select":
            value = child("input")
            path = node.get("path")
            if not isinstance(path, list):
                raise ProgramError("select 的 path 必须是位置列表")
            for key in path:
                if isinstance(value, dict) and isinstance(key, str) and key in value:
                    value = value[key]
                elif isinstance(value, list) and type(key) is int and 0 <= key < len(value):
                    value = value[key]
                else:
                    raise ProgramError(f"材料不包含所请求的位置：{key!r}")
        elif operation == "slice":
            source = child("input")
            start, end = node.get("start", 0), node.get("end")
            if not isinstance(source, (str, list)):
                raise ProgramError("slice 只能处理文字或列表")
            if type(start) is not int or start < 0 or (end is not None and (type(end) is not int or end < start)):
                raise ProgramError("slice 需要从零开始的有效范围")
            value = source[start:end]
        elif operation in ("join", "list"):
            inputs = node.get("inputs")
            if not isinstance(inputs, list):
                raise ProgramError(f"{operation} 需要 inputs 列表")
            values = [visit(item, depth + 1) for item in inputs]
            if operation == "join":
                separator = node.get("separator", "\n\n")
                if not isinstance(separator, str):
                    raise ProgramError("join 的分隔符必须是文字")
                rendered = [render_value(item) for item in values]
                if sum(map(len, rendered)) + len(separator) * max(0, len(rendered) - 1) > max_output_chars:
                    raise ProgramError("组合结果过长；请明确选择范围")
                value = separator.join(rendered)
            else:
                value = values
        elif operation == "object":
            fields = node.get("fields")
            if not isinstance(fields, dict):
                raise ProgramError("object 需要 fields 对象")
            value = {key: visit(item, depth + 1) for key, item in fields.items()}
        elif operation == "replace":
            source = child("input")
            old, new = node.get("old"), node.get("new")
            if not all(isinstance(item, str) for item in (source, old, new)) or not old:
                raise ProgramError("replace 需要文字输入、非空原文与替换文字")
            if len(source) + source.count(old) * (len(new) - len(old)) > max_output_chars:
                raise ProgramError("组合结果过长；请明确选择范围")
            value = source.replace(old, new)
        elif operation == "calculate":
            left, right = child("left"), child("right")
            if type(left) not in (int, float) or type(right) not in (int, float):
                raise ProgramError("calculate 只接受数值，不把文本或布尔值猜成数值")
            if not all(abs(item) <= 1e15 and math.isfinite(item) for item in (left, right)):
                raise ProgramError("数值超出初版计算范围")
            operator = node.get("operator")
            if operator == "+":
                value = left + right
            elif operator == "-":
                value = left - right
            elif operator == "*":
                value = left * right
            elif operator == "/" and right != 0:
                value = left / right
            else:
                raise ProgramError("不支持的计算或除数为零")
        elif operation == "render":
            value = render_value(child("input"))
        else:
            raise ProgramError(f"尚不支持的操作：{operation!r}")

        try:
            size = len(render_value(value))
        except (TypeError, ValueError, RecursionError, OverflowError) as exc:
            raise ProgramError("组合结果无法作为有限 JSON 或文字表达") from exc
        if size > max_output_chars:
            raise ProgramError("组合结果过长；请明确选择范围")
        return value

    value = visit(expression)
    return ProgramResult(value=value, text=render_value(value), reads=reads, steps=steps,
                         program_versions=resolved_programs)


def compile_activity(state: dict[str, Any]) -> ProgramResult:
    """采用的定义固定版本；它读取的材料版本在本次快照内解析。"""
    adopted = state.get("adopted")
    if adopted is not None:
        return execute_program(state, {"op": "call", "document": adopted["document"],
                                       "version": adopted["version"]},
                               program_versions=adopted.get("program_versions", {}))
    # 未采用任何组织时先提供全部材料；目录与明确请求不受此视图裁剪。
    return execute_program(state, {"op": "join", "inputs": [
        {"op": "join", "separator": "\n", "inputs": [
            {"op": "literal", "value": document["title"]},
            {"op": "read", "document": ident},
        ]} for ident, document in state.get("documents", {}).items()
    ]})
