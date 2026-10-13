"""Isolated UI fixture. No real model, Vault, or existing runtime state."""
import gc
import json
import os
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path[:0] = [str(ROOT / "web"), str(ROOT / "phase0")]

with tempfile.TemporaryDirectory(prefix="cognos-productization-") as temporary:
    path = Path(temporary)
    for name, value in {
        "COGNOS_VAULT_PATH": path / "vault",
        "COGNOS_THREAD_STATE_DIR": path / "state",
        "COGNOS_ORG_DB_PATH": path / "org.db",
        "COGNOS_INQUIRY_DB_PATH": path / "inquiry.db",
        "COGNOS_ACTIVITY_DB_PATH": path / "activities.db",
        "COGNOS_HANDOFF_DIR": path / "handoffs",
        "COGNOS_AUTH_DB_PATH": path / "auth.db",
    }.items():
        os.environ[name] = str(value)
    os.environ["COGNOS_EXTRA_PARTICIPANTS"] = ""
    os.environ["COGNOS_FRAME_SOURCES"] = ""
    os.environ["COGNOS_PI_ENABLED"] = "0"
    from codex_runtime import RuntimeReply
    from server import AppConfig, Participant, create_app
    import uvicorn

    class FixtureRuntime:
        async def send(self, message):
            return RuntimeReply(thread_id="ui-fixture", turn_id="ui-turn", text='{"answer":"UI fixture: no model inference"}')

    app = create_app(AppConfig(history_path=path / "field.jsonl", thread_state_dir=path / "state",
                              codex_cwd=ROOT, participant_instructions_path=ROOT / "web/participant_instructions.md"),
                     participants={"fixture": Participant("fixture", "Fixture AI", "no-inference", FixtureRuntime())})
    org = app.state.org_store.create_organization("同学协作检查")
    project = app.state.org_store.create_project(org["id"], "组织产品化检查")
    account = app.state.auth_store.create_account("alice", "fixture123", "同学 A")
    app.state.auth_store.create_account("bob", "fixture123", "同学 B")
    member = app.state.org_store.ensure_account_member(org["id"], account)
    app.state.org_store.add_project_member(project["id"], member["id"])
    app.state.history.append_message(author="旧作者", content="历史原文保留", project_id=project["id"])
    port = int(sys.argv[1])
    server = uvicorn.Server(uvicorn.Config(app, host="127.0.0.1", port=port, log_level="error"))

    @app.post("/__test__/shutdown")
    def shutdown():
        server.should_exit = True
        return {"status": "stopping"}

    print(json.dumps({"org": org["id"], "project": project["id"], "member": member["id"]}), flush=True)
    server.run()
    gc.collect()
