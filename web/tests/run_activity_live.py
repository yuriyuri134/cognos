"""用真实参与者继续一项设计活动；保留结果，不把它计作自动评测分数。"""

from __future__ import annotations

import argparse
import asyncio
import json
import os
import sys
import tempfile
from pathlib import Path

WEB = Path(__file__).resolve().parents[1]
ROOT = WEB.parent
sys.path.insert(0, str(WEB))
sys.path.insert(0, str(ROOT / "phase0"))


async def run(args):
    state = Path(os.environ.get("COGNOS_LIVE_STATE", Path(tempfile.gettempdir()) / "cognos-activity-live"))
    state.mkdir(parents=True, exist_ok=True)
    os.environ["COGNOS_VAULT_PATH"] = str(state / "vault")
    os.environ["COGNOS_THREAD_STATE_DIR"] = str(state / "runtime")
    os.environ["COGNOS_CODEX_CWD"] = str(ROOT)
    os.environ["COGNOS_EXTRA_PARTICIPANTS"] = ""
    os.environ["COGNOS_FRAME_SOURCES"] = ""
    from server import AppConfig, Participant, create_app

    instructions = (WEB / "participant_instructions.md").read_text(encoding="utf-8")
    if args.runtime == "codex":
        from codex_runtime import CodexRuntimeAdapter, JsonThreadStore
        model_name = args.model or "Codex App-Server"
        if args.check:
            print(json.dumps({"configured": True, "runtime": "codex", "model": model_name}, ensure_ascii=False))
            return
        adapter = CodexRuntimeAdapter(
            cwd=ROOT, model=args.model,
            state_store=JsonThreadStore(state / "participant.json"),
            developer_instructions=instructions)
    else:
        from deepseek_runtime import DeepSeekRuntimeAdapter, DeepSeekStateStore, load_deepseek_config
        configured = load_deepseek_config()
        if configured is None:
            print(json.dumps({"status": "unavailable", "reason": "现有参与者凭据不可用"}, ensure_ascii=False))
            return
        model_name = configured["model_name"]
        if args.check:
            print(json.dumps({"configured": True, "runtime": "deepseek", "model": configured["model"]}, ensure_ascii=False))
            return
        adapter = DeepSeekRuntimeAdapter(api_key=configured["api_key"], base_url=configured["base_url"],
            model=configured["model"], model_name=model_name,
            state_store=DeepSeekStateStore(state / "participant.json"),
            developer_instructions=instructions)
    app = create_app(AppConfig(history_path=state / "vault" / "field.jsonl", thread_state_dir=state / "state",
        codex_cwd=ROOT, participant_instructions_path=WEB / "participant_instructions.md"),
        participants={"live": Participant("live", "设计试用参与者", model_name, adapter)})
    metadata = state / "activity.json"
    if metadata.exists():
        ident = json.loads(metadata.read_text(encoding="utf-8"))["activity_id"]
    else:
        org = app.state.org_store.create_organization("Cognos 设计试用", "由 Codex 发起；与原运行数据隔离")
        project = app.state.org_store.create_project(org["id"], "隔离真实材料试用", "由 Codex 发起；不并入原项目")
        activity_title = "组织方式如何改变材料" if args.material_file else "组织的可修改性是否足以促进理解"
        activity = app.state.activity_store.create(project["id"], activity_title, actor="Codex")
        ident = activity["id"]
        source_path = Path(args.material_file or args.design_file)
        source_text = source_path.read_text(encoding="utf-8")
        source_title = "用户聊天式学习材料" if args.material_file else "总设计 v0.1（候选）"
        raw = app.state.history.append_message(author="Codex" if not args.material_file else "小林",
            content="把用户提供的真实材料放入隔离活动。" if args.material_file else "把总设计 v0.1 作为候选材料放入设计活动。",
            project_id=project["id"], activity_id=ident,
            imported_document={"author": "小林" if args.material_file else "Codex", "body": source_text})
        app.state.activity_store.change(ident, 1, [{"action": "add_document", "id": "source-material", "title": source_title,
            "format": "text", "body": source_text, "immutable": True}], actor="Codex", reason="引入隔离真实材料",
            allow_sources=True, occurrence_id=raw["id"],
            source_author="小林" if args.material_file else "Codex")
        metadata.write_text(json.dumps({"activity_id": ident, "project_id": project["id"]}, ensure_ascii=False), encoding="utf-8")
    existing = app.state.activity_store.runs(ident)
    match = next((item for item in existing if item["request_id"] == args.request_id), None)
    if match:
        print(json.dumps({"status": app.state.activity_service.view_run(match)["status"], "run_id": match["id"],
                          "note": "同一请求已有记录，未重放"}, ensure_ascii=False))
        return
    activity = app.state.activity_store.get(ident)
    content = args.content or (
        "这是 Codex 发起的实际设计试用，不是小林的新观点。请继续这份 Cognos 总设计："
        "‘正在采用的组织方式可以重新成为认识对象’，究竟已经解释了怎样更深入理解，"
        "还是主要给既有模型多了编辑流程的能力？请结合当前候选的具体结构推进这个分歧，"
        "形成一段能继续用于设计的内容，而不是重新列总体原则。"
        "你可以实际读取、修改或采用当前活动中的组织；没有内容上的理由，不必新造组合。"
        "请区分你的推论、实际操作和用户已确认的要求。"
    )
    print(json.dumps({"status": "starting", "activity_id": ident, "request_id": args.request_id,
                      "runtime": args.runtime, "model": model_name, "initiator": "Codex"}, ensure_ascii=False), flush=True)
    result = await app.state.activity_service.continue_activity(ident, content=content, target="live",
        revision=activity["revision"], request_id=args.request_id, initiator="Codex（设计试用）")
    messages = [item for item in app.state.history.read_all() if item.get("activity_run_id") == result["id"]]
    from cognitive_runtime import activity_view_from_runs
    current_activity = app.state.activity_store.get(ident)
    current_view = activity_view_from_runs(app.state.activity_store.runs(ident), current_activity["state"])
    cognitive_events = [{
        "status": event.get("status"),
        "transport_repairs": event.get("transport_repairs", []),
        "formation_updates": event.get("formation_updates", []),
        "tension_updates": event.get("tension_updates", []),
        "move": event.get("move"),
        "pause": event.get("pause"),
    } for event in result["events"]]
    report = {"activity_id": ident, "run_id": result["id"], "status": result["status"],
              "error": result.get("error"), "error_detail": result.get("error_detail"), "error_kind": result.get("error_kind"), "cause_kind": result.get("cause_kind"),
              "error_category": result.get("error_category"), "http_status": result.get("http_status"),
              "output_revision": current_activity["revision"], "calls": len(result["events"]),
              "replies": [item["content"] for item in messages if item.get("author_participant_id") == "live"],
              "results": [event["results"] for event in result["events"]],
              "cognitive_events": cognitive_events,
              "activity_view": current_view}
    (state / f"{args.request_id}.json").write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding="utf-8")
    print(json.dumps({"status": report["status"], "calls": report["calls"], "revision": report["output_revision"],
                      "error_kind": report["error_kind"], "cause_kind": report["cause_kind"],
                      "error": report["error"],
                      "error_detail": report["error_detail"],
                      "error_category": report["error_category"], "http_status": report["http_status"],
                      "report": str(state / f"{args.request_id}.json")}, ensure_ascii=False), flush=True)


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--check", action="store_true")
    parser.add_argument("--runtime", choices=("codex", "deepseek"), default="codex")
    parser.add_argument("--model", default="")
    parser.add_argument("--request-id", default="design-continuation-01")
    parser.add_argument("--content", default="")
    parser.add_argument("--design-file", default=str(ROOT / "docs" / "Cognos-总设计-v0.1-2026-09-10.md"))
    parser.add_argument("--material-file", default="")
    args = parser.parse_args()
    if not args.request_id.replace("-", "").isalnum():
        parser.error("request-id 只能包含字母、数字和短横线")
    asyncio.run(run(args))
