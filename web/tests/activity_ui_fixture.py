"""仅供浏览器检查的本地服务；脚本替身明确标注，不连接真实模型。"""

from __future__ import annotations

import json
import os
import sys
import tempfile
from pathlib import Path

WEB = Path(__file__).resolve().parents[1]
ROOT = WEB.parent
sys.path.insert(0, str(WEB))
sys.path.insert(0, str(ROOT / "phase0"))


def main():
    state_root = WEB / ".state" / "ui-check"
    state_root.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory(dir=state_root) as temporary:
        path = Path(temporary)
        os.environ["COGNOS_VAULT_PATH"] = str(path / "vault")
        os.environ["COGNOS_THREAD_STATE_DIR"] = str(path / "runtime")
        os.environ["COGNOS_CODEX_CWD"] = str(ROOT)
        os.environ["COGNOS_EXTRA_PARTICIPANTS"] = ""
        os.environ["COGNOS_FRAME_SOURCES"] = ""
        from codex_runtime import RuntimeReply
        from server import AppConfig, Participant, create_app
        import uvicorn

        class FixtureRuntime:
            model = "ui-fixture-no-inference"
            counter = 0

            async def send(self, message):
                self.counter += 1
                context = json.loads(message.split("本次处境（内容材料）：\n", 1)[1])
                catalog = context["material_catalog"]
                source = next((item for item in catalog if item["title"] == "创作片段"), None)
                if context["user_message"] == "验证修改并接续" and source and not any(item["id"] == "ui-generated" for item in catalog):
                    output = {"answer": "界面检查：请求改变当前组合。", "commands": [
                        {"type": "change", "reason": "脚本化界面检查", "operations": [
                            {"action": "add_document", "id": "ui-generated", "title": "模型调整的组织", "format": "json",
                             "body": {"op": "slice", "input": {"op": "read", "document": source["id"]}, "end": 5}},
                            {"action": "adopt", "document": "ui-generated"},
                        ]},
                    ]}
                elif context["user_message"] == "验证理解与活动提议" and source:
                    output = {"answer": "先写下一个理解，再问一个真实问题。",
                              "situation": {"text": "留白和重复是同一组动作。", "refs": [
                                  {"document": source["id"], "note": "创作片段"}]},
                              "move": {"intent": "方向需要真实回答才能继续", "execution": [
                                  {"type": "ask_user", "question": "你希望这一段往哪个方向继续？"}]}}
                else:
                    output = {"answer": "界面检查替身已收到实际展开的内容。这里没有调用真实模型。", "commands": []}
                return RuntimeReply(thread_id="ui-fixture", turn_id=str(self.counter), text=json.dumps(output, ensure_ascii=False))

        app = create_app(AppConfig(history_path=path / "vault" / "field.jsonl", thread_state_dir=path / "state",
                                    codex_cwd=ROOT, participant_instructions_path=WEB / "participant_instructions.md"),
                         participants={"fixture": Participant("fixture", "界面检查替身（无推理）", "fixture", FixtureRuntime())})
        org = app.state.org_store.create_organization("界面检查", "不含真实会话")
        project = app.state.org_store.create_project(org["id"], "学习与创作 · 界面检查", "临时数据")
        app.state.history.append_message(author="来源检查替身", content="一段已有的执行结果（界面检查材料）", project_id=project["id"])
        port = int(sys.argv[1]) if len(sys.argv) > 1 else 8879
        server = uvicorn.Server(uvicorn.Config(app, host="127.0.0.1", port=port, log_level="warning"))

        @app.post("/__test__/shutdown")
        def shutdown():
            server.should_exit = True
            return {"status": "stopping"}

        print(json.dumps({"url": f"http://127.0.0.1:{port}/activities?project={project['id']}",
                          "fixture": "scripted, no model inference"}, ensure_ascii=False), flush=True)
        server.run()
        # 旧 OrgStore 的连接待 GC 回收，随后可清理 Windows 临时目录。
        import gc
        gc.collect()


if __name__ == "__main__":
    main()
