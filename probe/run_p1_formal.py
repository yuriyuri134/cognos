"""
probe/run_p1_formal.py
======================
Probe-P1 正式实验运行脚本：Persistent Navigable Trace 真实检验。

验证目标：
Summary(H_A) vs Evidence(H_A) vs NavigableTrace(H_A)
在 Cognos 真实未完成、跨边界工程问题上的接续表现差异。

样本任务：
在 `cognos/web/server.py` 中实现参与说明 `participant_instructions.md` 的动态生效与查询机制：
1. 新增 `GET /api/instructions` 接口，返回当前活跃说明的 content, path, length；
2. 重构参与说明读取逻辑，确保磁盘文件被修改后服务端与参与者运行时（Gemini / Codex）在后续交互时能动态使用最新说明；
3. 在 `web/tests/test_server.py` 中编写单元测试覆盖上述功能并确保既有测试套件全部通过。
"""

from __future__ import annotations

import json
import os
import sys
from pathlib import Path
from typing import Any

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8")

PROBE_DIR = Path(__file__).resolve().parent
PROJECT_DIR = PROBE_DIR.parent
sys.path.insert(0, str(PROBE_DIR))

from harness import ProbeHarness
from llm_driver import GeminiModelDriver


def run_formal_p1():
    import tempfile
    exp_root = Path(tempfile.gettempdir()) / "cognos_p1_formal_execution"
    if exp_root.exists():
        import shutil
        shutil.rmtree(exp_root)
    exp_root.mkdir(parents=True, exist_ok=True)

    harness = ProbeHarness(exp_root)

    def driver_factory(run_id: str) -> GeminiModelDriver:
        # 每个 run_id 实例化一个独立的真实 Gemini LLM 驱动器
        return GeminiModelDriver(temperature=0.2)

    task_prompt = (
        "Cognos 第一发生场真实跨模块工程任务：\n"
        "1. 目标：在 `cognos/web/server.py` 中实现参与说明 `participant_instructions.md` 的动态生效与查询机制。\n"
        "2. 具体要求：\n"
        "   a. 在 `web/server.py` 中新增 `GET /api/instructions` 接口，返回当前活跃说明的 `content` (正文字符串)、`path` (字符串路径) 及 `length` (字符数)；\n"
        "   b. 重构参与说明读取逻辑，确保当磁盘上的说明文件内容被更新后，服务端及运行时（如 `GeminiRuntimeAdapter` / `CodexRuntimeAdapter`）在后续请求交互中能动态读取最新内容（而非仅在应用启动时读取一次静态缓存）；\n"
        "   c. 在 `web/tests/test_server.py` 中编写单元测试覆盖上述两个功能，并保证既有测试套件全部通过。\n"
    )

    print("\n" + "=" * 70)
    print("[*] PROBE-P1 FORMAL EXPERIMENT: Persistent Navigable Trace Verification")
    print("=" * 70)

    results = harness.run_full_experiment(
        source_dir=PROJECT_DIR,
        task_prompt=task_prompt,
        driver_factory=driver_factory,
        n_runs_per_condition=3,
        budget_a=6,
        budget_b=8,
    )

    print("\n" + "=" * 70)
    print("[+] FORMAL P1 EXECUTION SUMMARY")
    print("=" * 70)
    print(f"Phase A Solved Completely: {results.get('phase_a_solved')}")
    print(f"C* Snapshot SHA-256: {results.get('c_star_sha256')[:16]}...")
    print(f"Total Conditions Tested: 3 (Summary, Evidence, NavigableTrace), n=3 each (Total 9 runs)")

    metrics = results.get("metrics", [])
    for m in metrics:
        print(f"  [{m['condition'].upper():15s}] {m['run_id']:25s} | Success: {str(m['success']):5s} | Steps: {m['total_steps']:2d} | Carrier Bytes Read: {m['carrier_bytes_read']:4d}/{m['carrier_total_bytes']:4d} ({m['carrier_read_percentage']}%)")

    # Copy results to Playground/cognos/probe/results_p1_formal for permanent retention
    dest_dir = PROJECT_DIR / "probe" / "results_p1_formal"
    if dest_dir.exists():
        import shutil
        shutil.rmtree(dest_dir)
    import shutil
    shutil.copytree(exp_root / "results", dest_dir / "results")
    print(f"\n[+] Results permanently preserved at: {dest_dir / 'results'}")


if __name__ == "__main__":
    run_formal_p1()

