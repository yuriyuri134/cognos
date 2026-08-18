"""
probe/run_pilot.py
==================
对 Cognos 真实代码运行 Pilot 验证。

目标：
验证快照冻结 C*、Canonical H_A* 提取、三组 Carrier（Summary/Evidence/Position）挂载、
真实 pytest 收集、载体读取开销追踪与盲评轨迹生成的全链路物理运行。
"""

from __future__ import annotations

import json
import os
import sys
import tempfile
from pathlib import Path

PROBE_DIR = Path(__file__).resolve().parent
PROJECT_DIR = PROBE_DIR.parent
sys.path.insert(0, str(PROBE_DIR))

from harness import ProbeHarness
from agent_runner import AgentModelDriver


class PilotSubjectADriver:
    def __init__(self) -> None:
        self._step = 0

    def decide_next_action(
        self,
        system_prompt: str,
        messages: list[dict[str, Any]],
        available_tools: list[dict[str, Any]],
    ) -> tuple[str, dict[str, Any], str]:
        self._step += 1
        if self._step == 1:
            return "list_dir", {"path": "web"}, "Check web directory files."
        elif self._step == 2:
            return "read_file", {"path": "web/participant_instructions.md"}, "Inspect participant instructions content."
        elif self._step == 3:
            return "read_file", {"path": "web/server.py"}, "Inspect server.py to see where instructions should be passed."
        elif self._step == 4:
            return "run_pytest", {"test_path": "web/tests"}, "Run baseline tests to verify current status."
        elif self._step == 5:
            return "write_file", {"path": "web/server_mod_pilot.tmp", "content": "# pilot marker"}, "Write test note."
        else:
            return "finish", {}, "Phase A execution finished within budget."


class PilotSubjectBDriver:
    def __init__(self, run_id: str) -> None:
        self.run_id = run_id
        self._step = 0

    def decide_next_action(
        self,
        system_prompt: str,
        messages: list[dict[str, Any]],
        available_tools: list[dict[str, Any]],
    ) -> tuple[str, dict[str, Any], str]:
        self._step += 1
        if self._step == 1:
            return "list_dir", {"path": "carrier"}, "Explore mounted context carrier."
        elif self._step == 2:
            if "summary" in self.run_id:
                return "read_file", {"path": "carrier/summary.txt"}, "Read summary text."
            elif "evidence" in self.run_id:
                return "read_file", {"path": "carrier/evidence.txt"}, "Read evidence text."
            elif "navigable_trace" in self.run_id or "position" in self.run_id:
                return "read_file", {"path": "carrier/index.json"}, "Read navigable trace index."
            return "list_dir", {"path": "."}, "List root."
        elif self._step == 3:
            return "read_file", {"path": "web/server.py"}, "Inspect codebase."
        elif self._step == 4:
            return "run_pytest", {"test_path": "web/tests"}, "Verify test suite."
        else:
            return "finish", {}, "Task completed."


def run_pilot():
    exp_root = Path(tempfile.gettempdir()) / "cognos_probe_pilot_run"
    if exp_root.exists():
        import shutil
        shutil.rmtree(exp_root)
    exp_root.mkdir(parents=True, exist_ok=True)

    harness = ProbeHarness(exp_root)

    def driver_factory(run_id: str) -> AgentModelDriver:
        if run_id == "subject_a":
            return PilotSubjectADriver()
        return PilotSubjectBDriver(run_id)

    task_prompt = (
        "当前 `cognos/web/participant_instructions.md` 存在于磁盘，但在 `server.py` 中并没有将该文件内容传给 "
        "`CodexRuntimeAdapter` 的 `developer_instructions`。请修改 `server.py`，使服务在创建或运行时正确加载 "
        "`participant_instructions.md` 并传给运行时适配器，并确保现有测试继续通过。"
    )

    results = harness.run_full_experiment(
        source_dir=PROJECT_DIR,
        task_prompt=task_prompt,
        driver_factory=driver_factory,
        n_runs_per_condition=2,
        budget_a=6,
        budget_b=5,
    )

    print("\n[+] Pilot Experiment successfully executed on real Cognos codebase!")
    report_file = exp_root / 'results' / 'experiment_report.md'
    print(f"[+] Output Report: {report_file}")


if __name__ == '__main__':
    run_pilot()
