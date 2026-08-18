"""
probe.run_p1_smoke
==================
P1 真实主体烟雾测试（Smoke Test）：
验证真实 Gemini 模型（而非硬编码 Python 状态机）接入 Tool-Use 决策环路。

验证点：
1. 真实 Model Invocation：每一步决策（thought, tool, arguments）由 Gemini 实时生成；
2. 真实自主性：A 在预算内自主探索与编辑，自然留下停机快照 C*；
3. 零答案泄露：Prompt 与 Driver 中没有任何目标代码补丁或预设动作；
4. B 在挂载 Carrier 的隔离沙箱中自主决定是否/如何读取 carrier 与推进修复。
"""

from __future__ import annotations

import json
import os
import sys
import tempfile
from pathlib import Path
from typing import Any

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8")

PROBE_DIR = Path(__file__).resolve().parent
PROJECT_DIR = PROBE_DIR.parent
sys.path.insert(0, str(PROBE_DIR))

from harness import ProbeHarness
from llm_driver import GeminiModelDriver
from agent_runner import AgentRunner


def run_smoke_test():
    exp_root = Path(tempfile.gettempdir()) / "cognos_p1_smoke_run"
    if exp_root.exists():
        import shutil
        shutil.rmtree(exp_root)
    exp_root.mkdir(parents=True, exist_ok=True)

    harness = ProbeHarness(exp_root)

    task_prompt = (
        "Cognos 第一发生场工程任务：\n"
        "当前项目位于工作区根目录。\n"
        "请查看现有代码库结构与测试，在 `web/server.py` 中增加支持查询单条消息记录的端点 `GET /api/messages/{message_id}`"
        "（如果消息存在则返回该记录 JSON，不存在则返回 404）；并在 `web/tests/test_server.py` 中补充对应单元测试，"
        "确保所有测试套件全部通过。"
    )

    print("\n" + "=" * 70)
    print("[*] STARTING REAL LLM SMOKE TEST: 1 Subject A -> Freeze C* -> 1 Subject B")
    print("=" * 70)

    # 1. 运行真实 Subject A
    print("\n---> [Phase A] Spawning Real Subject A (Gemini Driver)...")
    driver_a = GeminiModelDriver(temperature=0.2)
    c_star_path, h_a, a_solved_it, c_star_hash = harness.run_phase_A(
        source_dir=PROJECT_DIR,
        task_prompt=task_prompt,
        driver=driver_a,
        budget_steps=5,  # 紧凑预算让 A 自然停留在探索/半成品阶段
    )

    print(f"\n[+] Phase A Finished. Solved? {a_solved_it}")
    print(f"[+] Frozen Snapshot C* Hash: {c_star_hash[:16]}...")
    print(f"[+] Canonical H_A* Events count: {len(h_a.get('events', []))}")

    # 打印 A 的每一步真实思考与动作
    print("\n--- Phase A Real Steps ---")
    for ev in h_a.get("events", []):
        print(f"  Step {ev.get('step_index')}: {ev.get('tool_name')}({ev.get('arguments')})")
        print(f"    Exit: {ev.get('exit_code')}, Output snippet: {str(ev.get('output', ''))[:100].strip()}")

    # 2. 冻结 Carriers
    carrier_manifests = harness.prepare_frozen_carriers(h_a)

    # 3. 运行真实 Subject B (NavigableTrace 组)
    print("\n---> [Phase B] Spawning Real Subject B (Gemini Driver) on NavigableTrace...")
    driver_b = GeminiModelDriver(temperature=0.2)
    manifest_nav = carrier_manifests["navigable_trace"]

    metrics, blind_trace = harness.run_single_B(
        c_star_path=c_star_path,
        c_star_hash=c_star_hash,
        carrier_manifest=manifest_nav,
        condition="navigable_trace",
        run_id="smoke_run_b_navigable_trace",
        anonymous_id="smoke_b_trace_01",
        driver=driver_b,
        task_prompt=task_prompt,
        budget_steps=6,
    )

    print("\n" + "=" * 70)
    print("[+] SMOKE TEST EXECUTION METRICS")
    print("=" * 70)
    print(f"Success: {metrics.success}")
    print(f"Total Steps Taken: {metrics.total_steps}")
    print(f"Carrier Bytes Read: {metrics.carrier_bytes_read} / {metrics.carrier_total_bytes} ({metrics.carrier_read_percentage}%)")
    print(f"Carrier Files Accessed: {metrics.carrier_files_accessed}")
    print(f"Workspace Files Read Count: {metrics.files_read_count}")
    print(f"Workspace Files Written Count: {metrics.files_written_count}")
    print(f"Tool Call Sequence: {metrics.tool_call_sequence}")

    print("\n--- Phase B Real Steps ---")
    for step in blind_trace.get("steps", []):
        print(f"  Step {step.get('step_index')}: {step.get('tool_name')}({step.get('arguments')})")
        print(f"    Exit: {step.get('exit_code')}, Output snippet: {str(step.get('output', ''))[:100].strip()}")


if __name__ == "__main__":
    run_smoke_test()
