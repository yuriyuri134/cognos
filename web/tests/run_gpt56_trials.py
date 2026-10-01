"""Run isolated real GPT-5.6 high trials against the current Activity kernel.

Credentials are supplied only through the process environment. The script
creates a temporary organization/database and writes its evidence to the
caller-provided output path; it never touches the user's production vault.
"""

from __future__ import annotations

import argparse
import asyncio
import json
import os
import tempfile
from pathlib import Path

from httpx import ASGITransport, AsyncClient

WEB_DIR = Path(__file__).resolve().parents[1]
PHASE0_DIR = WEB_DIR.parent / "phase0"
import sys
sys.path.insert(0, str(WEB_DIR))
sys.path.insert(0, str(PHASE0_DIR))

from deepseek_runtime import DeepSeekRuntimeAdapter, DeepSeekStateStore
from server import AppConfig, Participant, create_app


def trim(value):
    if isinstance(value, dict):
        return {k: trim(v) for k, v in value.items() if k not in {"rendered_prompt"}}
    if isinstance(value, list):
        return [trim(v) for v in value]
    if isinstance(value, str) and len(value) > 20_000:
        return value[:20_000] + "\n...[truncated]"
    return value


async def run(output: Path) -> None:
    root = Path(tempfile.mkdtemp(prefix="cognos-gpt56-trial-"))
    instruction = root / "instructions.md"
    instruction.write_text("你是 Cognos 的参与者。优先使用开放活动信封。", encoding="utf-8")
    common = dict(history_path=root / "vault" / "field.jsonl",
                  thread_state_dir=root / "state",
                  codex_cwd=root,
                  participant_instructions_path=instruction)

    def participant(pid: str, label: str) -> Participant:
        adapter = DeepSeekRuntimeAdapter(
            api_key=os.environ["COGNOS_TRIAL_API_KEY"],
            base_url=os.environ.get("COGNOS_TRIAL_BASE_URL", "https://api.a6api.com/v1"),
            model=os.environ.get("COGNOS_TRIAL_MODEL", "gpt-5.6"),
            model_name=label,
            state_store=DeepSeekStateStore(root / "state" / f"{pid}.json"),
        )
        return Participant(pid, label, label, adapter)

    app = create_app(AppConfig(**common), participants={
        "open": participant("open", "GPT-5.6 high · 开放处境"),
        "learn": participant("learn", "GPT-5.6 high · 学习构造"),
    })
    evidence = {"runtime": {"model": os.environ.get("COGNOS_TRIAL_MODEL", "gpt-5.6"),
                             "reasoning_effort": os.environ.get("DEEPSEEK_REASONING_EFFORT", "high"),
                             "endpoint": os.environ.get("COGNOS_TRIAL_BASE_URL", "https://api.a6api.com/v1")},
                "trials": []}
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://trial") as client:
        org = (await client.post("/api/orgs", json={"name": "GPT-5.6 隔离验收"})).json()["organization"]
        project = (await client.post(f"/api/orgs/{org['id']}/projects", json={"title": "开放活动验收"})).json()["project"]

        open_activity = (await client.post(f"/api/projects/{project['id']}/activities", json={"title": "开放现实处境"})).json()["activity"]
        response = await client.post(f"/api/activities/{open_activity['id']}/changes", json={
            "revision": 1, "operations": [{"action": "add_document", "id": "opening",
                "title": "用户原始表达", "format": "text",
                "body": "我不知道如何实现自己的理想。", "immutable": True}]})
        open_activity = response.json()["activity"]
        open_run = await client.post(f"/api/activities/{open_activity['id']}/continue", json={
            "revision": open_activity["revision"], "request_id": "gpt56-open-1",
            "content": "我不知道如何实现自己的理想。", "target": "open"})
        open_result = open_run.json()
        evidence["trials"].append({"name": "open_situation_first", "response": trim(open_result)})
        open_current = open_result.get("activity", open_activity)
        waiting_event = open_result.get("run", {}).get("events", [{}])[-1]
        waiting_move = waiting_event.get("move_id")
        if waiting_move:
            open_next = await client.post(
                f"/api/activities/{open_current['id']}/moves/{waiting_move}/answer",
                json={"revision": open_current["revision"], "request_id": "gpt56-open-answer",
                      "answer": "我想真正理解数学。现在吃住没有问题，但没有钱去读正规的数学课程；我还说不清这和理想究竟怎样连接。"})
        else:
            open_next = await client.post(f"/api/activities/{open_current['id']}/continue", json={
                "revision": open_current["revision"], "request_id": "gpt56-open-2",
                "content": "我一直想学数学，但现在没有钱，也不知道这和理想的关系。", "target": "open"})
        evidence["trials"].append({"name": "open_situation_answer", "response": trim(open_next.json())})

        learn_activity = (await client.post(f"/api/projects/{project['id']}/activities", json={"title": "学习构造"})).json()["activity"]
        response = await client.post(f"/api/activities/{learn_activity['id']}/changes", json={
            "revision": 1, "operations": [{"action": "add_document", "id": "math",
                "title": "学习材料", "format": "text",
                "body": "特征向量是在线性变换作用下方向保持不变的向量。", "immutable": True}]})
        learn_activity = response.json()["activity"]
        learn_run = await client.post(f"/api/activities/{learn_activity['id']}/continue", json={
            "revision": learn_activity["revision"], "request_id": "gpt56-learn-1",
            "content": "先不要给我学习计划。请从这段材料出发，尝试构造一个能让理解发生的例子。", "target": "learn"})
        evidence["trials"].append({"name": "learning_construction", "response": trim(learn_run.json())})
        learn_current = learn_run.json().get("activity", learn_activity)
        learn_second = await client.post(f"/api/activities/{learn_current['id']}/continue", json={
            "revision": learn_current["revision"], "request_id": "gpt56-learn-2",
            "content": "现在请实际构造这个二维例子，写出矩阵和向量，并把构造结果保存为活动材料。", "target": "learn"})
        evidence["trials"].append({"name": "learning_construction_result", "response": trim(learn_second.json())})

        creative_activity = (await client.post(f"/api/projects/{project['id']}/activities", json={"title": "创作材料"})).json()["activity"]
        response = await client.post(f"/api/activities/{creative_activity['id']}/changes", json={
            "revision": 1, "operations": [{"action": "add_document", "id": "creative",
                "title": "未解释片段", "format": "text",
                "body": "洗脸。抬头。天桥上的风。另一个世界像一道还没有命名的裂缝。", "immutable": True}]})
        creative_activity = response.json()["activity"]
        creative_run = await client.post(f"/api/activities/{creative_activity['id']}/continue", json={
            "revision": creative_activity["revision"], "request_id": "gpt56-creative-1",
            "content": "先从这些片段继续写，不要先解释象征，也不要制定计划。", "target": "open"})
        evidence["trials"].append({"name": "creative_material", "response": trim(creative_run.json())})

        for activity_id, name in [(open_activity["id"], "open_contexts"),
                                  (learn_activity["id"], "learning_contexts"),
                                  (creative_activity["id"], "creative_contexts")]:
            contexts = (await client.get(f"/api/activities/{activity_id}/contexts")).json()
            evidence["trials"].append({"name": name, "response": trim(contexts)})

    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text(json.dumps(evidence, ensure_ascii=False, indent=2), encoding="utf-8")
    print(output)


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--output", required=True)
    args = parser.parse_args()
    asyncio.run(run(Path(args.output)))
