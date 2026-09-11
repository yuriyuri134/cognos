"""当前 Codex 直接参与已有设计活动，使用实际保存的记录；不调用或模拟模型。"""
from pathlib import Path
from types import SimpleNamespace
import asyncio, json, sys

WEB = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(WEB))
from history import AppendOnlyHistory
from activity_store import ActivityStore
from activity_service import ActivityService


async def main():
    root = WEB / ".state" / "activity-live"
    ident = json.loads((root / "activity.json").read_text(encoding="utf-8"))["activity_id"]
    history = AppendOnlyHistory(root / "vault" / "field.jsonl")
    store = ActivityStore(root / "state" / "activities.db")
    conversation = SimpleNamespace(history=history, _send_lock=asyncio.Lock())
    service = ActivityService(store, conversation)
    phase = sys.argv[1]
    if phase == "reorganize":
        current = store.get(ident)
        if "retrieved-disagreement" in current["state"]["documents"]:
            raise SystemExit("本次并置已经保存，未重放。")
        runs = store.runs(ident)
        documents = [next(run for run in runs if run["request_id"] == "actual-acquisition-" + name)["events"][0]["results"][0]["document"]
                     for name in ("model-proposal", "codex-correction")]
        reason = "Codex 依据两次实际取材，将模型提出的必要条件与自己此前的纠正并置。当前分歧是重开组织需要哪些条件；查询成功不裁定谁的认识论解释成立。"
        raw = history.append_message(author="Codex", content=reason, project_id=current["project_id"], activity_id=ident,
                                     direct_design_participation=True)
        expression = {"op":"object", "fields":{label:{"op":"select", "input":{"op":"read", "document":document}, "path":["records", 0]}
                      for label, document in zip(("模型的候选条件", "Codex 的纠正，非用户新确认"), documents)}}
        current = store.change(ident, current["revision"], [
            {"action":"add_document", "id":"retrieved-disagreement", "title":"按真实来路重开设计分歧", "format":"json", "body":expression},
            {"action":"adopt", "document":"retrieved-disagreement"},
            {"action":"set_focus", "text":"区分重开组织的可执行条件与方向为何形成；取材与并置使两者可比较，不能替代内容判断。"}],
            actor="Codex", reason=reason, occurrence_id=raw["id"])
        from activity_program import compile_activity
        output = compile_activity(current["state"])
        print(json.dumps({"revision":current["revision"], "adopted":current["state"]["adopted"],
                          "source_adopted":current["state"]["source_adopted"], "reads":output.reads,
                          "authors":[item["author"] for item in output.value.values()]}, ensure_ascii=False, indent=2))
        return
    if phase not in ("model-proposal", "codex-correction"):
        raise SystemExit("需要指定 model-proposal 或 codex-correction")
    query = {"provider": "project_history", "contains": "预期" if phase == "model-proposal" else "不是小林的新确认",
             "author": "设计试用参与者" if phase == "model-proposal" else "Codex（设计试用）", "limit": 10}
    request_id = "actual-acquisition-" + phase
    old = next((run for run in store.runs(ident) if run["request_id"] == request_id), None)
    if old:
        print(json.dumps({"note":"已执行，未重放", "run":old}, ensure_ascii=False))
        return
    current = store.get(ident)
    reason = ("Codex 直接接续已有设计：先取回真实模型关于预期不符的提议。" if phase == "model-proposal" else
              "Codex 将取材方向改为取回自己已经提交的纠正，以便与模型提议一起重新理解设计分歧；不把纠正当作用户的新确认。")
    raw = history.append_message(author="Codex", content=reason, project_id=current["project_id"], activity_id=ident,
                                 direct_design_participation=True)
    if "retrieval-parameters" not in current["state"]["documents"]:
        operations = [{"action":"add_document", "id":"retrieval-parameters", "title":"当前需要取得的设计材料", "format":"json", "body":query},
                      {"action":"add_document", "id":"retrieval-definition", "title":"依当前设计需要取材", "format":"json", "body":{"op":"read", "document":"retrieval-parameters"}},
                      {"action":"adopt_source", "document":"retrieval-definition"}]
    else:
        operations = [{"action":"revise_document", "document":"retrieval-parameters", "body":query}]
    current = store.change(ident, current["revision"], operations, actor="Codex", reason=reason, occurrence_id=raw["id"])
    run = await service.acquire_material(ident, revision=current["revision"], request_id=request_id, initiator="Codex")
    (root / (request_id + ".json")).write_text(json.dumps(run, ensure_ascii=False, indent=2), encoding="utf-8")
    print(json.dumps(run, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    asyncio.run(main())
