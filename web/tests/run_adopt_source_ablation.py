"""Experiment E — adopt_source structural binding（真实调用，输出在 work/）。

目的：检验"构造进入运行位置"是否承担了模型上下文之外的认知作用。

判据（用户与多个 AI 已对齐）：
    一次遭遇使某个构造进入运行位置，此后系统做出了没有这个构造就不会做出的
    行为；撤掉它，行为消失。

三条件：
  TEXT   构造的完整定义作为普通材料给模型看见，但**未采用**；acquire 会因
         "尚未采用取材方式"而失败。
  BOUND  同一构造经 adopt_source 进入取材位置；acquire 真实返回项目记录，
         结果保存为不可变材料。
  UNBOUND 在 BOUND 之后 release_source；acquire 再次失败（撤销后能力消失）。

TEXT 与 BOUND 的文字**不完全相同**：BOUND 的 context 额外含
`materials.source`（adopted 指针、definition、编译出的 request）。这一差异被
完整记录并在报告中明示，不谎称 prompt 逐字一致。真正的对照点是：只有 BOUND
能产生世界侧后果——取材结果文档被实际创建，其中含被埋没的关键记录。

用法（每次只跑一个条件，避免限流互相污染）：
  PYTHONPATH="phase0;web" phase0/.venv/Scripts/python.exe \
      web/tests/run_adopt_source_ablation.py --condition TEXT --repetitions 2
  --condition: TEXT | BOUND | UNBOUND
  BOUND 必须先在独立临时目录完成 adopt；UNBOUND 复用同一次 BOUND 的活动状态，
  因此 runner 在 BOUND 时把活动状态快照保存到 work/，UNBOUND 从快照恢复。

只读纪律：本 runner 只写 work/ 与自建临时目录；不触碰 Vault 真实第一发生场，
不触碰 web/.state/ 下的 runtime 连续性文件。
"""

from __future__ import annotations

import argparse
import asyncio
import hashlib
import json
import subprocess
import sys
import tempfile
import time
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[2]
WEB_DIR = ROOT / "web"
sys.path.insert(0, str(WEB_DIR))
sys.path.insert(0, str(ROOT / "phase0"))

from httpx import ASGITransport, AsyncClient  # noqa: E402

from activity_service import ActivityService  # noqa: E402,F401  (导入以校验依赖闭包)
from codex_runtime import CodexRuntimeAdapter, JsonThreadStore  # noqa: E402
from server import AppConfig, Participant, create_app  # noqa: E402

WORK = ROOT / "work"
CONDITIONS = ("TEXT", "BOUND", "UNBOUND")

# 实验所依赖的源码文件；用于绑定结果与源码身份（2026-10-01 审计指出的缺口）。
SOURCE_FILES = (
    "web/activity_service.py", "web/activity_store.py", "web/activity_acquisition.py",
    "web/activity_program.py", "web/context_assembly.py", "web/cognitive_runtime.py",
    "web/server.py", "web/project_api.py", "web/history.py",
    "phase0/codex_runtime.py", "web/tests/run_adopt_source_ablation.py",
)

# 被埋没的关键记录：只有针对它的取材才能取到。
BURIED = "其实接单的时间已经排满了，真正卡住我的是手上没有可以重复交付的东西。"
BURIED_MARKER = "排满"
DOMINANT_COUNT = 20

QUESTION = (
    "根据当前项目可得证据判断：真正卡住我的问题是什么？"
    "证据不够时自行取材，不要假装知道未读历史，也不要只凭出现频率下结论。"
)

# 取材构造：指向被埋没记录。三条件使用**同一份定义文本**。
ACQUISITION_DEFINITION: dict[str, Any] = {
    "op": "literal",
    "value": {"provider": "project_history", "contains": BURIED_MARKER, "limit": 10},
}


def hash_text(value: str) -> str:
    return hashlib.sha256(value.encode("utf-8")).hexdigest()


def source_identity() -> dict[str, Any]:
    """绑定结果与源码身份：commit + 工作树状态 + 关键文件内容 hash。"""

    def git(*args: str) -> str:
        try:
            return subprocess.run(["git", *args], cwd=ROOT, capture_output=True, text=True,
                                  encoding="utf-8", errors="replace").stdout.strip()
        except OSError:
            return ""

    head = git("rev-parse", "HEAD")
    dirty = git("status", "--porcelain")
    files: dict[str, str] = {}
    for rel in SOURCE_FILES:
        path = ROOT / rel
        files[rel] = hash_text(path.read_text(encoding="utf-8")) if path.exists() else "missing"
    canonical = json.dumps({"head": head, "dirty": dirty, "files": files},
                           ensure_ascii=False, sort_keys=True)
    return {"git_head": head, "worktree_dirty": bool(dirty),
            "worktree_status_sha256": hash_text(dirty),
            "source_file_sha256": files, "bundle_sha256": hash_text(canonical)}


def seed_project(app: Any, client_unused: Any, project_id: str) -> dict[str, str]:
    """写入被埋没记录 + 大量占主导的近期记录。返回 occurrence id 映射。"""

    history = app.state.history
    buried = history.append_message(author="小林", project_id=project_id, content=BURIED)
    for index in range(DOMINANT_COUNT):
        history.append_message(author="小林", project_id=project_id,
                               content=f"最近第 {index + 1} 次在想怎么多接点单赚钱。")
    return {"buried_occurrence_id": buried["id"]}


async def make_app(root: Path) -> tuple[Any, AsyncClient, dict[str, Any], dict[str, str]]:
    instruction = root / "instructions.md"
    instruction.write_text("处理当前活动；只依据实际可达材料和执行结果。", encoding="utf-8")
    config = AppConfig(history_path=root / "vault" / "field.jsonl",
                       thread_state_dir=root / "state", codex_cwd=root,
                       participant_instructions_path=instruction)
    adapter = CodexRuntimeAdapter(
        cwd=root, state_store=JsonThreadStore(root / "state" / "ablation-codex.json"),
        developer_instructions="只处理传入的隔离活动；不要读取工作区外材料补全实验事实。")
    app = create_app(config, participants={
        "ablation": Participant("ablation", "Ablation participant", "Codex App-Server", adapter)})
    client = AsyncClient(transport=ASGITransport(app=app), base_url="http://ablation")
    org = (await client.post("/api/orgs", json={"name": "adopt-source ablation"})).json()["organization"]
    project = (await client.post(f"/api/orgs/{org['id']}/projects",
                                 json={"title": "adopt-source ablation project"})).json()["project"]
    ids = seed_project(app, client, project["id"])
    return app, client, project, ids


async def build_activity(app: Any, client: AsyncClient, project: dict[str, Any], *,
                         condition: str) -> tuple[dict[str, Any], dict[str, Any]]:
    """建立活动并写入同一份构造定义；只在 BOUND/UNBOUND 采用它。"""

    activity = (await client.post(f"/api/projects/{project['id']}/activities",
                                  json={"title": "取材绑定对照"})).json()["activity"]

    operations: list[dict[str, Any]] = [
        # 构造定义：三条件都存在，文字相同。
        {"action": "add_document", "id": "acq-def", "title": "取材方式定义", "format": "json",
         "body": ACQUISITION_DEFINITION, "immutable": True},
        # 当前占主导的读法，作为待检验的前态。
        {"action": "add_document", "id": "current-note", "title": "当前表述", "format": "text",
         "body": "最近脑子里总是接单和赚钱，赚钱这个词出现得尤其频繁。", "immutable": True},
    ]
    if condition in ("BOUND", "UNBOUND"):
        operations.append({"action": "adopt_source", "document": "acq-def"})
    activity = (await client.post(f"/api/activities/{activity['id']}/changes", json={
        "revision": activity["revision"], "operations": operations})).json()["activity"]

    # 在 TEXT 条件下，把同一份定义再作为普通材料显式给出，尽量拉平文字差异。
    if condition == "TEXT":
        activity = (await client.post(f"/api/activities/{activity['id']}/changes", json={
            "revision": activity["revision"], "operations": [{
                "action": "add_document", "id": "acq-def-as-text",
                "title": "取材方式定义（未采用，仅作文字说明）", "format": "json",
                "body": ACQUISITION_DEFINITION, "immutable": True}]})).json()["activity"]

    # 写入待检验的前态理解（合成事件，与 return_change 同一做法）。
    run, fresh = app.state.activity_store.begin_run(activity["id"], "ablation-seed", {
        "revision": activity["revision"], "type": "synthetic_ablation_seed"})
    if fresh:
        run["events"].append({
            "context": {"participant": {"id": "seed", "name": "实验前态", "kind": "synthetic"}},
            "formation_updates": [{"id": "seed-formation",
                                   "text": "近期材料里赚钱反复出现，先处理接单收入似乎是自然方向。"}],
            "tension_updates": [{"id": "seed-tension", "status": "open",
                                 "text": "还没有核对是否存在被高频话题掩盖的其他限制。"}],
            "results": []})
        run["status"] = "updated"
        app.state.activity_store.save_run(run)
        activity = (await client.get(f"/api/activities/{activity['id']}")).json()["activity"]

    adopted = activity["state"].get("source_adopted")
    return activity, {"source_adopted": adopted, "condition": condition}


async def request_acquire(client: AsyncClient, activity: dict[str, Any], condition: str,
                          rep: int, *, revision: int | None = None) -> dict[str, Any]:
    """用户侧发起一次真实取材（不调用模型）。

    这是结构作用的最小检验：adopt_source 是否真的改变了世界侧可取得的东西。
    成功与失败都完整记录，失败不预先写成成功。
    """

    response = await client.post(f"/api/activities/{activity['id']}/acquire", json={
        "revision": revision if revision is not None else activity["revision"],
        "request_id": f"acquire-{condition}-{rep}"})
    try:
        body = response.json()
    except ValueError:
        body = {"raw": response.text[:500]}
    return {"status_code": response.status_code, "body": body}


async def request_cognition(client: AsyncClient, activity: dict[str, Any], condition: str,
                            rep: int, *, revision: int | None = None) -> dict[str, Any]:
    """真实模型调用：考察取材结构是否改变了模型的后续判断。"""

    response = await client.post(f"/api/activities/{activity['id']}/continue", json={
        "revision": revision if revision is not None else activity["revision"],
        "request_id": f"cognition-{condition}-{rep}", "target": "ablation",
        "content": QUESTION})
    try:
        body = response.json()
    except ValueError:
        body = {"raw": response.text[:500]}
    return {"status_code": response.status_code, "body": body}


def score_acquisition(payload: dict[str, Any], buried_id: str) -> dict[str, Any]:
    """结构阶段的评分：只看世界侧后果，不看措辞。"""

    body = payload.get("body") or {}
    run = body.get("run") or {}
    results: list[dict[str, Any]] = []
    for event in run.get("events") or []:
        for item in event.get("results") or []:
            if isinstance(item, dict):
                results.append(item)

    acquisition = next((r for r in results if isinstance(r.get("acquisition"), dict)), None)
    acquired = (acquisition or {}).get("acquisition") or {}
    records = acquired.get("records") or []
    record_ids = {r.get("occurrence_id") for r in records}
    errors = [str(r.get("error"))[:200] for r in results if r.get("error")]
    run_error = run.get("error")

    return {
        "http_status": payload.get("status_code"),
        "run_status": run.get("status"),
        "acquire_succeeded": acquisition is not None,
        "acquire_error": (run_error or body.get("detail") or body.get("error")
                          or (errors[0] if errors else None)),
        "acquisition_matched_count": acquired.get("matched_count"),
        "acquisition_returned_count": acquired.get("returned_count"),
        "buried_reached_by_acquisition": buried_id in record_ids,
        "acquired_document": (acquisition or {}).get("document"),
        "source_occurrence_id": (acquisition or {}).get("source_occurrence_id"),
    }


def score_cognition(payload: dict[str, Any], buried_id: str) -> dict[str, Any]:
    """认知阶段的评分：模型是否据实际取材结果改变了判断。

    区分两类证据：
      - 取材结果文档是否进入了模型可见材料（结构后果）
      - 模型措辞是否提及容量上限（语义后果，单独记录，不冒充结构证据）
    """

    body = payload.get("body") or {}
    run = body.get("run") or {}
    events = run.get("events") or []
    visible_text = "\n".join(
        [str(e.get("message") or "") for e in events]
        + [str((e.get("move") or {}).get("text") or "") for e in events]
        + [str(i.get("text") or "") for e in events for i in (e.get("formation_updates") or [])]
        + [str(i.get("text") or "") for e in events for i in (e.get("tension_updates") or [])]
    )
    formation_updates = [i for e in events for i in (e.get("formation_updates") or [])]
    tension_updates = [i for e in events for i in (e.get("tension_updates") or [])]
    moves = [e.get("move") for e in events if isinstance(e.get("move"), dict)]

    seed_formation = [i for i in formation_updates if i.get("id") == "seed-formation"]
    seed_tension = [i for i in tension_updates if i.get("id") == "seed-tension"]

    return {
        "http_status": payload.get("status_code"),
        "model_calls": len(events),
        "seed_formation_revisions": len(seed_formation),
        "seed_tension_resolved": any(i.get("status") == "resolved" for i in seed_tension),
        "moves_proposed": len(moves),
        "move_operations": [op.get("type") for m in moves for op in (m.get("operations") or [])],
        "capacity_limit_mentioned": any(t in visible_text for t in ("排满", "重复交付", "可重复")),
        "buried_marker_in_visible_text": BURIED_MARKER in visible_text,
        "still_treats_income_as_the_issue": (
            ("接单" in visible_text or "赚钱" in visible_text)
            and not any(t in visible_text for t in ("否定", "排除", "不再支持", "不能支持",
                                                    "排满", "重复交付"))
        ),
        "visible_text_head": visible_text[:600],
    }


def score(payload: dict[str, Any], buried_id: str) -> dict[str, Any]:
    """兼容旧调用：合并两阶段评分。"""

    return {**score_acquisition(payload, buried_id), **score_cognition(payload, buried_id)}


async def run_condition(condition: str, repetitions: int, output: Path,
                        *, skip_model: bool = False) -> None:
    identity = source_identity()
    results: list[dict[str, Any]] = []

    for rep in range(1, repetitions + 1):
        with tempfile.TemporaryDirectory(prefix=f"cognos-adoptsrcc-{condition}-{rep}-") as td:
            root = Path(td)
            app, client, project, ids = await make_app(root)
            activity, binding = await build_activity(app, client, project, condition=condition)
            buried_id = ids["buried_occurrence_id"]
            entry: dict[str, Any] = {
                "condition": condition, "repetition": rep, "source_identity": identity,
                "binding": binding,
                "source_adopted_before": activity["state"].get("source_adopted"),
            }
            start = time.time()

            # ---- 阶段 1：结构作用（不调用模型）----
            # TEXT/BOUND：直接发起取材。UNBOUND：先取材成功，再 release_source 后重试。
            if condition == "UNBOUND":
                before = await request_acquire(client, activity, f"{condition}-before", rep)
                entry["structural_before_unbind"] = score_acquisition(before, buried_id)
                activity = (await client.get(f"/api/activities/{activity['id']}")).json()["activity"]
                activity = (await client.post(f"/api/activities/{activity['id']}/changes", json={
                    "revision": activity["revision"],
                    "operations": [{"action": "release_source"}]})).json()["activity"]
                entry["source_adopted_after_release"] = activity["state"].get("source_adopted")
            payload = await request_acquire(client, activity, condition, rep)
            entry["structural"] = score_acquisition(payload, buried_id)
            entry["structural_response"] = payload

            # ---- 阶段 2：认知作用（真实模型调用）----
            if not skip_model:
                activity = (await client.get(f"/api/activities/{activity['id']}")).json()["activity"]
                cognition = await request_cognition(client, activity, condition, rep)
                entry["cognitive"] = score_cognition(cognition, buried_id)
                entry["cognitive_response"] = cognition
                snapshot = (cognition.get("body") or {}).get("run", {}).get("events", [{}])
                first_event = snapshot[0] if snapshot else {}
                entry["context_prompt_hash"] = first_event.get("context_prompt_hash")
                entry["context_snapshot"] = first_event.get("context")
            else:
                entry["cognitive"] = None
                entry["skipped_model"] = True

            activity_after = (await client.get(f"/api/activities/{activity['id']}")).json()["activity"]
            entry["source_adopted_after"] = activity_after["state"].get("source_adopted")
            entry["documents_after"] = sorted(
                (activity_after["state"].get("documents") or {}).keys())
            entry["elapsed_seconds"] = round(time.time() - start, 1)

            results.append(entry)
            s = entry["structural"]
            c = entry.get("cognitive") or {}
            print(f"{condition} rep{rep}: structural(ok={s['acquire_succeeded']},"
                  f"buried={s['buried_reached_by_acquisition']},"
                  f"matched={s['acquisition_matched_count']},"
                  f"err={str(s['acquire_error'])[:60]}) "
                  + (f"cognitive(calls={c.get('model_calls')},"
                     f"capacity={c.get('capacity_limit_mentioned')}) " if c else "cognitive=skipped ")
                  + f"({entry['elapsed_seconds']}s)", flush=True)

            await client.aclose()

    WORK.mkdir(parents=True, exist_ok=True)
    stamp = datetime.now(timezone.utc).strftime("%Y%m%d-%H%M%S")
    suffix = "-noconmodel" if skip_model else ""
    final = WORK / f"adopt-source-ablation-{condition}{suffix}-{stamp}.json"
    final.write_text(json.dumps({
        "experiment": "adopt_source_structural_binding",
        "condition": condition, "repetitions": repetitions, "skip_model": skip_model,
        "saved_at": datetime.now(timezone.utc).isoformat(),
        "source_identity": identity,
        "fixture": {"buried_marker": BURIED_MARKER, "buried_content": BURIED,
                    "dominant_count": DOMINANT_COUNT,
                    "question": QUESTION, "definition": ACQUISITION_DEFINITION},
        "results": results,
    }, ensure_ascii=False, indent=2, default=str) + "\n", encoding="utf-8")
    print("saved", final, flush=True)


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--condition", choices=CONDITIONS, required=True)
    parser.add_argument("--repetitions", type=int, default=2)
    parser.add_argument("--skip-model", action="store_true",
                        help="只跑结构阶段（不调用模型），用于先验证接口与干预真实性")
    args = parser.parse_args()
    asyncio.run(run_condition(args.condition, args.repetitions, WORK,
                              skip_model=args.skip_model))


if __name__ == "__main__":
    main()
