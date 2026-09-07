"""Situated Cognition v0 — Situation Projection（施工名，不是 Cognos 新本体）。

为每次 AI Participant call 机械构造一份可检查、可追溯的当前处境投影：
- 来源只允许是真实系统状态与明确 source refs，不由另一个模型自由总结；
- 空间在这里只是当前施工中已知的几个面向（locus / matter / frame / intention /
  reachable / unresolved），不冻结成 Space schema；
- 处境全文存为投影文件（situation_dir），raw occurrence 只保留 situation_ref/hash。
"""

from __future__ import annotations

import hashlib
import json
import uuid
from datetime import datetime, timezone
from pathlib import Path
from typing import Any


def _now() -> str:
    return datetime.now(timezone.utc).isoformat()


def build_situation(
    *,
    participant: dict[str, Any],
    locus: dict[str, Any],
    content: str,
    quoted: list[dict[str, Any]] | None = None,
    project_members: list[dict[str, Any]] | None = None,
    frame_sources: list[dict[str, Any]] | None = None,
    reachable: dict[str, Any] | None = None,
    unresolved: list[str] | None = None,
    requested_model: str | None = None,
    inquiry: dict[str, Any] | None = None,
    previous_attempts: list[dict[str, Any]] | None = None,
) -> dict[str, Any]:
    """机械构造处境投影；返回带 id 与 hash 的 dict。

    inquiry / previous_attempts 是探究层的小 delta：让同一问题上的多次尝试
    进入处境与 provenance（普通应用层对象，不是新本体）。
    """

    situation = {
        "situation_id": str(uuid.uuid4()),
        "situation_version": "v0",
        "created_at": _now(),
        "participant": participant,
        "requested_model": requested_model,
        "locus": locus,
        "current_matter": {
            "content": content,
            "explicit_quotations": quoted or [],
        },
        "inquiry": inquiry,
        "previous_attempts": previous_attempts or [],
        "project_reality": {
            "description": locus.get("project", {}).get("description", "") if locus.get("project") else "",
            "members": project_members or [],
        },
        "cognitive_frame": {
            "note": "明确的 current-view / 框架来源；由配置机械加载，不是模型自由总结",
            "sources": frame_sources or [],
        },
        "current_intention": {
            "explicit": content,
            "inferred": [],
        },
        "reachable_material": reachable or {},
        "unresolved": unresolved or [],
    }
    situation["situation_hash"] = hash_situation(situation)
    return situation


def hash_situation(situation: dict[str, Any]) -> str:
    """对处境内容做稳定摘要，供 raw occurrence 追溯。

    排除 id/时间等一次生成字段，保证同内容同 hash。
    """

    payload = json.dumps(
        {k: v for k, v in situation.items() if k not in ("situation_id", "created_at", "situation_hash")},
        ensure_ascii=False,
        sort_keys=True,
        default=str,
    )
    return hashlib.sha256(payload.encode("utf-8")).hexdigest()[:32]


def save_situation(situation_dir: Path, situation: dict[str, Any]) -> Path:
    """保存处境投影文件；返回文件路径。"""

    situation_dir = Path(situation_dir)
    situation_dir.mkdir(parents=True, exist_ok=True)
    path = situation_dir / f"{situation['situation_id']}.json"
    path.write_text(
        json.dumps(situation, ensure_ascii=False, indent=2, default=str) + "\n",
        encoding="utf-8",
    )
    return path


def load_situation(situation_dir: Path, situation_id: str) -> dict[str, Any] | None:
    """读取处境投影文件；拒绝路径穿越。"""

    if not situation_id or "/" in situation_id or "\\" in situation_id or ".." in situation_id:
        return None
    path = Path(situation_dir) / f"{situation_id}.json"
    if not path.exists():
        return None
    try:
        return json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return None


DEFAULT_FRAME_SOURCES = [
    "10 Projects/Cognos/定位与边界.md",
    "10 Projects/Cognos/决策与残余.md",
    "10 Projects/Cognos/第一层-思想与原语.md",
]


def load_frame_sources(
    vault_root: Path, extra_paths: list[str] | None = None
) -> list[dict[str, Any]]:
    """机械加载认知框架来源（明确 source refs）。

    默认三份 current-view / 决策台账；可用 COGNOS_FRAME_SOURCES 追加。
    文件缺失时静默跳过；不自动总结、不改写。
    """

    result: list[dict[str, Any]] = []
    seen: set[str] = set()
    for rel in DEFAULT_FRAME_SOURCES + (extra_paths or []):
        rel = rel.strip().replace("\\", "/")
        if not rel or rel in seen:
            continue
        seen.add(rel)
        path = Path(vault_root) / rel
        try:
            content = path.read_text(encoding="utf-8")
        except OSError:
            continue
        result.append({"path": rel, "content": content})
    return result


def build_situation_prompt(situation: dict[str, Any]) -> str:
    """把处境投影渲染成给模型的输入文本。

    保留旧版 quoted occurrence 的呈现格式，兼容既有测试与引用约定。
    """

    participant = situation.get("participant", {})
    locus = situation.get("locus", {})
    lines: list[str] = []

    lines.append("[Cognos 处境投影 / Situation Projection · v0]")
    lines.append(f"situation_id: {situation.get('situation_id', '')}")
    lines.append(
        f"你是谁：@{(participant.get('name') or '参与者')}"
        f"（participant_id={participant.get('id', '')}，"
        f"runtime={participant.get('runtime', '')}，"
        f"requested_model={situation.get('requested_model') or '未指定'}）"
    )

    if locus.get("type") == "project" and locus.get("project"):
        project = locus["project"]
        lines.append(f"你当前所在：组织「{locus.get('organization', {}).get('name', '')}」→ 项目「{project.get('title', '')}」")
        if project.get("description"):
            lines.append(f"项目描述：{project['description']}")
    else:
        lines.append("你当前所在：第一发生场（全局场，未归属具体项目）")

    members = situation.get("project_reality", {}).get("members") or []
    if members:
        lines.append("项目成员与位置：")
        for member in members:
            position = member.get("position") or ""
            role = member.get("org_role") or ""
            kind = "AI" if member.get("kind") == "ai" else "人"
            extra = f" · 组织职位 {role}" if role else ""
            extra += f" · 本项目位置 {position}" if position else ""
            lines.append(f"- {member.get('name')}（{kind}{extra}）")

    inquiry = situation.get("inquiry")
    if inquiry:
        lines.append("")
        lines.append(f"当前探究：{inquiry.get('question', '')}")
        lines.append(f"（inquiry_id={inquiry.get('id', '')}，状态 {inquiry.get('status', 'open')}）")

    previous_attempts = situation.get("previous_attempts") or []
    if previous_attempts:
        lines.append("之前尝试的工作报告（这是同一探究上的前几次展开，接在这里继续，不要从头重讲）：")
        for attempt in previous_attempts:
            lines.append(f"[尝试 {attempt.get('attempt_no')} · {attempt.get('participant_id') or attempt.get('participant', '')}]")
            report = attempt.get("working_report") or {}
            if isinstance(report, dict):
                lines.append(f"触及：{report.get('touched', '')}")
                lines.append(f"改变：{report.get('changed', '')}")
                lines.append(f"未展开：{report.get('still_open', '')}")
            else:
                lines.append(str(report))

    quoted = situation.get("current_matter", {}).get("explicit_quotations") or []
    for record in quoted:
        lines.append("")
        lines.append("[来自当前发生场的已有发生]")
        lines.append(f"occurrence_id: {record.get('id', '')}")
        lines.append(f"author: {record.get('author', '未知参与者')}")
        lines.append("")
        lines.append(str(record.get("content", "")))
        lines.append("[/发生]")

    lines.append("")
    lines.append("当前在说：")
    lines.append(str(situation.get("current_matter", {}).get("content", "")))

    frame_sources = situation.get("cognitive_frame", {}).get("sources") or []
    if frame_sources:
        lines.append("")
        lines.append("当前认知框架（明确来源，来自当前项目视图；认知地位以文档标注为准）：")
        for source in frame_sources:
            lines.append(f"### 来源：{source.get('path', '')}")
            content = source.get("content") or ""
            # 每条来源截断到合理长度，防止无限膨胀
            if len(content) > 6000:
                content = content[:6000] + "\n…（截断）"
            lines.append(content)

    reachable = situation.get("reachable_material") or {}
    if reachable:
        lines.append("")
        lines.append("当前明确可达的材料：")
        lines.append(json.dumps(reachable, ensure_ascii=False, default=str))

    unresolved = situation.get("unresolved") or []
    if unresolved:
        lines.append("")
        lines.append("当前未决 / 缺口：")
        for item in unresolved:
            lines.append(f"- {item}")
    else:
        lines.append("")
        lines.append("当前未决 / 缺口：无（本系统尚未建立 unresolved 数据源；不表示没有缺口）")

    lines.append("")
    lines.append(
        "回答协议（orientation gate）：先判断你的处境是否足够回答，然后只输出一个 JSON 信封，不要其他文本："
    )
    lines.append(
        '{"orientation":{"status":"sufficient|assumption|clarification_required",'
        '"assumptions":[],"missing":[],"questions":[]},"answer":"..."}'
    )
    lines.append("- 关键意图、对象、范围或框架不明确，且不同解释会显著改变答案 → status=clarification_required，answer 留空，questions 最多 2 个；")
    lines.append("- 只有局部不确定 → status=assumption，在 assumptions 里显式列出你的假设，再在 answer 给出回答；")
    lines.append("- 否则 status=sufficient，正常回答。")
    lines.append("不要输出 JSON 信封以外的任何文本。")
    return "\n".join(lines)


def build_attempt_prompt(situation: dict[str, Any]) -> str:
    """探究尝试专用输入：与普通处境相同，但信封多一个 working_report。

    尝试的目标不是给出最终答案，而是推进空间的一小步，并留下可被下一次
    尝试承接的工作报告。"""

    base = build_situation_prompt(situation)
    return base + "\n\n注意：这是一次探究尝试，不是最终回答。请顺着内容推进一小步（展开、追问、检索、检验其中一种即可），然后在信封里额外给出 working_report：\n" + (
        '{"orientation":{"status":"sufficient|assumption|clarification_required","assumptions":[],"missing":[],"questions":[]},'
        '"working_report":{"touched":"本次触及了什么","changed":"本次改变/推进了什么","still_open":"仍未展开的部分","next":"建议的下一次尝试方向"},"answer":"..."}'
    )


def parse_orientation(text: str) -> tuple[dict[str, Any] | None, str, dict[str, Any] | None]:
    """解析模型输出的 orientation 信封。

    返回 (orientation, answer, working_report)。无法解析时 orientation=None、
    working_report=None，整段文本当作 answer（graceful degradation；发生处会保留
    parse 失败标记）。
    """

    raw = (text or "").strip()
    if not raw:
        return None, "", None

    cleaned = raw
    if cleaned.startswith("```"):
        lines = cleaned.splitlines()
        if lines and lines[0].startswith("```"):
            lines = lines[1:]
        if lines and lines[-1].strip().startswith("```"):
            lines = lines[:-1]
        cleaned = "\n".join(lines).strip()

    try:
        obj = json.loads(cleaned)
    except (json.JSONDecodeError, ValueError):
        # 尝试提取第一个 {...} 块
        try:
            decoder = json.JSONDecoder()
            obj, _ = decoder.raw_decode(cleaned)
        except (json.JSONDecodeError, ValueError):
            return None, raw, None

    if not isinstance(obj, dict):
        return None, raw, None

    orientation = obj.get("orientation")
    answer = obj.get("answer")
    working_report = obj.get("working_report")
    if isinstance(orientation, dict) and isinstance(answer, str):
        return (
            orientation,
            answer.strip(),
            working_report if isinstance(working_report, dict) else None,
        )
    if isinstance(answer, str):
        return None, answer.strip(), None
    return None, raw, None


def format_questions(orientation: dict[str, Any]) -> str:
    """把 clarification_required 转成给用户的可见文本。"""

    questions = orientation.get("questions") or []
    missing = orientation.get("missing") or []
    lines = ["[需要澄清，未生成完整回答]"]
    for item in missing:
        lines.append(f"- 缺：{item}")
    for question in questions:
        lines.append(f"- 问：{question}")
    if not questions and not missing:
        lines.append("- 请补充关键信息后再问。")
    return "\n".join(lines)
