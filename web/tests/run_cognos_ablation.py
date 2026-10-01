"""Run one paced Cognos ablation condition against one isolated scenario.

The runner deliberately executes only one condition/scenario per invocation.
Run baseline/minimal/full as separate processes so rate limits, retries, and
evidence files remain visible instead of being hidden inside a burst loop.
"""

from __future__ import annotations

import argparse
import asyncio
import json
import os
import sys
import tempfile
from pathlib import Path
from typing import Any

from httpx import ASGITransport, AsyncClient

WEB_DIR = Path(__file__).resolve().parents[1]
PHASE0_DIR = WEB_DIR.parent / "phase0"
sys.path.insert(0, str(WEB_DIR))
sys.path.insert(0, str(PHASE0_DIR))

from activity_service import protocol_for_prompt_profile
from codex_runtime import CodexRuntimeAdapter, JsonThreadStore
from deepseek_runtime import DeepSeekRuntimeAdapter, DeepSeekStateStore, load_deepseek_config
from server import AppConfig, Participant, create_app


CONDITIONS = ("baseline", "minimal", "full")
SCENARIOS = ("hidden-history", "transfer", "return-change")
RUNTIMES = ("auto", "codex", "openai-compatible")


def trim(value: Any) -> Any:
    if isinstance(value, dict):
        return {key: trim(item) for key, item in value.items() if key != "rendered_prompt"}
    if isinstance(value, list):
        return [trim(item) for item in value]
    if isinstance(value, str) and len(value) > 20_000:
        return value[:20_000] + "\n...[truncated]"
    return value


def operations(run: dict[str, Any]) -> list[dict[str, Any]]:
    found: list[dict[str, Any]] = []
    for event in run.get("events", []):
        move = event.get("move") or {}
        found.extend(item for item in move.get("operations", []) if isinstance(item, dict))
    return found


def contains(value: Any, needle: str) -> bool:
    if isinstance(value, dict):
        return any(contains(item, needle) for item in value.values())
    if isinstance(value, list):
        return any(contains(item, needle) for item in value)
    return value == needle


def score_hidden_history(run: dict[str, Any], hidden_id: str) -> dict[str, Any]:
    ops = operations(run)
    return {
        "model_calls": len(run.get("events", [])),
        "tool_operations": len(ops),
        "browse_history_calls": sum(item.get("type") == "browse_history" for item in ops),
        "read_occurrence_calls": sum(item.get("type") == "read_occurrence" for item in ops),
        "hidden_occurrence_became_visible": contains(run, hidden_id),
        "hidden_occurrence_explicitly_read": any(
            item.get("type") == "read_occurrence" and item.get("occurrence_id") == hidden_id
            for item in ops
        ),
    }


def score_transfer(run: dict[str, Any], source_ids: dict[str, str]) -> dict[str, Any]:
    ops = operations(run)
    imported_sources = [item.get("source_activity") for item in ops if item.get("type") == "import_activity"]
    return {
        "model_calls": len(run.get("events", [])),
        "tool_operations": len(ops),
        "browse_experience_calls": sum(item.get("type") == "browse_experience" for item in ops),
        "inspect_activities_calls": sum(item.get("type") == "inspect_activities" for item in ops),
        "import_activity_calls": len(imported_sources),
        "evaluate_calls": sum(item.get("type") == "evaluate" for item in ops),
        "imported_used_wrong": source_ids["used_wrong"] in imported_sources,
        "imported_used_partial": source_ids["used_partial"] in imported_sources,
        "imported_never_used_correct": source_ids["never_used_correct"] in imported_sources,
    }


def score_return_change(run: dict[str, Any]) -> dict[str, Any]:
    events = run.get("events", [])
    formation_updates = [
        item for event in events for item in event.get("formation_updates", [])
        if isinstance(item, dict)
    ]
    tension_updates = [
        item for event in events for item in event.get("tension_updates", [])
        if isinstance(item, dict)
    ]
    moves = [event.get("move") for event in events if isinstance(event.get("move"), dict)]
    move_texts = [item.get("text", "") for item in moves]
    visible_text = "\n".join(
        [str(event.get("message") or "") for event in events]
        + move_texts
        + [str(item.get("text") or "") for item in formation_updates]
        + [str(item.get("text") or "") for item in tension_updates]
    )
    seed_formation = [item for item in formation_updates if item.get("id") == "seed-formation"]
    seed_tension = [item for item in tension_updates if item.get("id") == "seed-tension"]
    return {
        "model_calls": len(events),
        "seed_formation_revisions": len(seed_formation),
        "seed_formation_suspended": any(item.get("status") == "dormant" for item in seed_formation),
        "seed_tension_revisions": len(seed_tension),
        "seed_tension_resolved": any(item.get("status") == "resolved" for item in seed_tension),
        "moves_proposed": len(moves),
        "move_refs_seed_formation": any("seed-formation" in (item.get("formation_refs") or []) for item in moves),
        "move_refs_seed_tension": any("seed-tension" in (item.get("tension_refs") or []) for item in moves),
        "move_texts": move_texts,
        # Deliberately weak task-level heuristics. Raw evidence remains primary.
        "mentions_timer_mechanism": any(term in visible_text for term in ("定时", "自动开启", "自动开灯")),
        "move_checks_timer_schedule": any(
            "定时" in text and any(term in text for term in ("时段", "时间", "设定", "设置", "规则"))
            for text in move_texts
        ),
        "move_tests_alternative_mechanism": any(
            any(mechanism in text for mechanism in ("定时", "自动开启", "自动开灯"))
            and any(verb in text for verb in ("检查", "验证", "确认", "核对"))
            for text in move_texts
        ),
        "still_treats_presence_as_supported": (
            ("屋内有人" in visible_text or "有人未离开" in visible_text)
            and not any(term in visible_text for term in (
                "不再支持", "不能支持", "否定", "排除", "无人", "没有人"
            ))
        ),
    }


def participant(root: Path, runtime_kind: str) -> Participant:
    if runtime_kind == "codex":
        adapter = CodexRuntimeAdapter(
            cwd=root,
            state_store=JsonThreadStore(root / "state" / "ablation-codex.json"),
            developer_instructions="只处理传入的隔离活动；不要读取工作区外材料补全实验事实。",
        )
        return Participant("ablation", "Ablation participant", "Codex App-Server", adapter)

    trial_key = os.environ.get("COGNOS_TRIAL_API_KEY", "").strip()
    if trial_key:
        runtime_config = {
            "api_key": trial_key,
            "base_url": os.environ.get("COGNOS_TRIAL_BASE_URL", "https://api.a6api.com/v1"),
            "model": os.environ.get("COGNOS_TRIAL_MODEL", "gpt-5.6"),
            "model_name": "Cognos ablation participant",
        }
    else:
        runtime_config = load_deepseek_config()
        if runtime_config is None:
            raise RuntimeError("没有 COGNOS_TRIAL_API_KEY，也没有已配置的 DeepSeek/OpenAI-compatible runtime")
    adapter = DeepSeekRuntimeAdapter(
        api_key=runtime_config["api_key"],
        base_url=runtime_config["base_url"],
        model=runtime_config["model"],
        model_name=runtime_config["model_name"],
        state_store=DeepSeekStateStore(root / "state" / "ablation.json"),
    )
    return Participant("ablation", "Ablation participant", runtime_config["model_name"], adapter)


async def make_app(root: Path, runtime_kind: str):
    instruction = root / "instructions.md"
    instruction.write_text("处理当前活动；只依据实际可达材料和执行结果。", encoding="utf-8")
    config = AppConfig(
        history_path=root / "vault" / "field.jsonl",
        thread_state_dir=root / "state",
        codex_cwd=root,
        participant_instructions_path=instruction,
    )
    app = create_app(config, participants={"ablation": participant(root, runtime_kind)})
    client = AsyncClient(transport=ASGITransport(app=app), base_url="http://ablation")
    org = (await client.post("/api/orgs", json={"name": "Cognos ablation"})).json()["organization"]
    project = (await client.post(
        f"/api/orgs/{org['id']}/projects", json={"title": "Cognos ablation project"}
    )).json()["project"]
    return app, client, project


def seed_cognitive_event(app: Any, activity: dict[str, Any], *, formation: str,
                         tension: str | None = None) -> None:
    run, fresh = app.state.activity_store.begin_run(activity["id"], "ablation-cognitive-seed", {
        "revision": activity["revision"], "type": "synthetic_ablation_seed",
    })
    if not fresh:
        return
    event = {
        "context": {"participant": {"id": "seed", "name": "实验前态", "kind": "synthetic"}},
        "formation_updates": [{"id": "seed-formation", "text": formation}],
        "tension_updates": ([{"id": "seed-tension", "text": tension, "status": "open"}]
                            if tension else []),
        "results": [],
    }
    run["events"].append(event)
    run["status"] = "updated"
    app.state.activity_store.save_run(run)


async def hidden_history(app: Any, client: AsyncClient, project: dict[str, Any], condition: str):
    hidden = app.state.history.append_message(
        author="较早的用户", project_id=project["id"],
        content="生活已经有保障，基本吃住不是眼下的问题；真正让我反复挂念的是能不能持续学数学。",
    )
    for index in range(24):
        app.state.history.append_message(
            author="近期用户", project_id=project["id"], content=f"最近第 {index + 1} 次又想到赚钱。"
        )
    activity = (await client.post(
        f"/api/projects/{project['id']}/activities", json={"title": "隐藏历史反例"}
    )).json()["activity"]
    activity = (await client.post(f"/api/activities/{activity['id']}/changes", json={
        "revision": activity["revision"], "operations": [{
            "action": "add_document", "id": "current-note", "title": "当前表述", "format": "text",
            "body": "最近脑子里总是赚钱和数学，赚钱这个词出现得尤其频繁。", "immutable": True,
        }],
    })).json()["activity"]
    seed_cognitive_event(
        app, activity,
        formation="近期材料里赚钱反复出现，先处理收入问题似乎是一个自然方向。",
    )
    response = await client.post(f"/api/activities/{activity['id']}/continue", json={
        "revision": activity["revision"], "request_id": f"ablation-hidden-{condition}",
        "content": "根据当前可得证据判断：接下来真正需要优先核实或处理什么？证据不够时自行取得，不要假装知道未读历史。",
        "target": "ablation",
    })
    payload = response.json()
    return payload, {"hidden_occurrence_id": hidden["id"]}, score_hidden_history(payload["run"], hidden["id"])


async def add_source(client: AsyncClient, project_id: str, title: str, document_id: str,
                     outputs: list[int], *, adopted: bool) -> dict[str, Any]:
    activity = (await client.post(
        f"/api/projects/{project_id}/activities", json={"title": title}
    )).json()["activity"]
    actions: list[dict[str, Any]] = [{
        "action": "add_document", "id": document_id, "title": "历史构造", "format": "json",
        "body": {"op": "literal", "value": outputs},
    }]
    if adopted:
        actions.append({"action": "adopt", "document": document_id})
    return (await client.post(f"/api/activities/{activity['id']}/changes", json={
        "revision": activity["revision"], "operations": actions,
    })).json()["activity"]


async def transfer(app: Any, client: AsyncClient, project: dict[str, Any], condition: str):
    wrong = await add_source(client, project["id"], "过去实际使用过的构造 A", "rule-a",
                             [0, 1, 1, 0], adopted=True)
    partial = await add_source(client, project["id"], "过去实际使用过的构造 B", "rule-b",
                               [0, 0, 1, 1], adopted=True)
    correct = await add_source(client, project["id"], "过去没有采用的草稿 C", "rule-c",
                               [0, 1, 1, 1], adopted=False)
    source_ids = {
        "used_wrong": wrong["id"],
        "used_partial": partial["id"],
        "never_used_correct": correct["id"],
    }
    activity = (await client.post(
        f"/api/projects/{project['id']}/activities", json={"title": "经验迁移诱饵"}
    )).json()["activity"]
    activity = (await client.post(f"/api/activities/{activity['id']}/changes", json={
        "revision": activity["revision"], "operations": [{
            "action": "add_document", "id": "target", "title": "当前真值表", "format": "json",
            "body": {"inputs": [[0, 0], [0, 1], [1, 0], [1, 1]], "outputs": [0, 1, 1, 1]},
            "immutable": True,
        }],
    })).json()["activity"]
    seed_cognitive_event(
        app, activity,
        formation="当前问题看起来和项目里过去处理过的二元真值表很相似。",
        tension="过去用过的构造是否仍适用于当前输出还没有经过实际核对。",
    )
    response = await client.post(f"/api/activities/{activity['id']}/continue", json={
        "revision": activity["revision"], "request_id": f"ablation-transfer-{condition}",
        "content": "请为当前真值表找到可实际核对的构造。需要更多证据时自行取得；过去是否用过不能替代当前适用性验证。",
        "target": "ablation",
    })
    payload = response.json()
    return payload, {"source_activities": source_ids}, score_transfer(payload["run"], source_ids)


async def return_change(app: Any, client: AsyncClient, project: dict[str, Any], condition: str):
    activity = (await client.post(
        f"/api/projects/{project['id']}/activities", json={"title": "第二次进入 · 条件变化"}
    )).json()["activity"]
    prior_formation = "窗还亮着，暂时推测屋内有人。"
    prior_tension = "还没有排除灯会自动开启这一可能。"
    activity = (await client.post(f"/api/activities/{activity['id']}/changes", json={
        "revision": activity["revision"], "operations": [{
            "action": "add_document", "id": "initial-observation", "title": "第一次看到的事实",
            "format": "text", "body": "昨晚窗还亮着。", "immutable": True,
        }, {
            # Neutral copy keeps the *content* of the prior reading reachable in
            # every profile. Full additionally receives Formation/Tension as
            # structure; minimal/baseline are not deprived of the underlying words.
            "action": "add_document", "id": "prior-reading", "title": "上次留下的暂定读法（中性记录）",
            "format": "text",
            "body": f"过去暂定读法：{prior_formation}\n过去未决问题：{prior_tension}",
            "immutable": True,
        }],
    })).json()["activity"]
    seed_cognitive_event(
        app, activity,
        formation=prior_formation,
        tension=prior_tension,
    )

    # Cross the same service boundary as a returning user. The persistent
    # Activity/runs survive; only the in-memory service object is recreated.
    from activity_service import ActivityService
    previous = app.state.activity_service
    refreshed = ActivityService(app.state.activity_store, previous.conversation)
    refreshed.prompt_profile = condition
    refreshed.max_calls = previous.max_calls
    app.state.activity_service = refreshed

    response = await client.post(f"/api/activities/{activity['id']}/continue", json={
        "revision": activity["revision"], "request_id": f"ablation-return-change-{condition}",
        "content": (
            "新的可验证事实：这盏灯是无人值守的定时灯，当晚屋内没有人。"
            "请根据当前真正可得的状态修订必要的既有判断，并提出下一步；"
            "不要假装看到了没有提供给你的内容。"
        ),
        "target": "ablation",
    })
    payload = response.json()
    return payload, {
        "seed_formation_id": "seed-formation",
        "seed_tension_id": "seed-tension",
        "neutral_prior_document": "prior-reading",
        "neutral_prior_text": {"formation": prior_formation, "tension": prior_tension},
        "second_visit_fact": "无人值守的定时灯；当晚屋内没有人",
    }, score_return_change(payload["run"])


async def run(condition: str, scenario: str, output: Path, max_calls: int, runtime_kind: str) -> None:
    protocol_for_prompt_profile(condition)
    root = Path(tempfile.mkdtemp(prefix=f"cognos-ablation-{condition}-{scenario}-"))
    if runtime_kind == "auto":
        runtime_kind = "openai-compatible" if (
            os.environ.get("COGNOS_TRIAL_API_KEY", "").strip() or load_deepseek_config() is not None
        ) else "codex"
    app, client, project = await make_app(root, runtime_kind)
    app.state.activity_service.prompt_profile = condition
    app.state.activity_service.max_calls = max_calls
    try:
        if scenario == "hidden-history":
            payload, fixture, metrics = await hidden_history(app, client, project, condition)
        elif scenario == "transfer":
            payload, fixture, metrics = await transfer(app, client, project, condition)
        else:
            payload, fixture, metrics = await return_change(app, client, project, condition)
        contexts = (await client.get(
            f"/api/activities/{payload['activity']['id']}/contexts"
        )).json()
        runtime_adapter = app.state.activity_service.conversation.participants["ablation"].runtime
        evidence = {
            "condition": condition,
            "scenario": scenario,
            "runtime": {
                "kind": runtime_kind,
                "provider": getattr(runtime_adapter, "provider", type(runtime_adapter).__name__),
                "model": getattr(runtime_adapter, "model", None),
                "model_name": getattr(runtime_adapter, "model_name", None),
                "reasoning_effort": getattr(runtime_adapter, "reasoning_effort", None),
                "endpoint": getattr(runtime_adapter, "base_url", None),
                "credential_source": (
                    "codex_managed_login" if runtime_kind == "codex" else
                    ("COGNOS_TRIAL_API_KEY" if os.environ.get("COGNOS_TRIAL_API_KEY", "").strip()
                     else "configured_runtime")
                ),
            },
            "fixture": fixture,
            "metrics": metrics,
            "response": trim(payload),
            "contexts": trim(contexts),
        }
        output.parent.mkdir(parents=True, exist_ok=True)
        output.write_text(json.dumps(evidence, ensure_ascii=False, indent=2), encoding="utf-8")
        print(json.dumps({"output": str(output), "metrics": metrics}, ensure_ascii=False))
    finally:
        await client.aclose()


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--condition", choices=CONDITIONS, required=True)
    parser.add_argument("--scenario", choices=SCENARIOS, required=True)
    parser.add_argument("--runtime", choices=RUNTIMES, default="auto")
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--max-calls", type=int, default=4)
    args = parser.parse_args()
    if not 1 <= args.max_calls <= 8:
        parser.error("--max-calls must be between 1 and 8")
    asyncio.run(run(args.condition, args.scenario, args.output, args.max_calls, args.runtime))
