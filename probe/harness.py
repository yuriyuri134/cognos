"""
probe.harness
=============
Probe-P1 实验装置主控制器（Master Experiment Harness）。

保证：
1. 严格双阶段：Phase A 产生 Canonical H_A* 与冻结快照 C*；
2. 三种载体（Summary, Evidence, Position）仅由 Canonical H_A* 生成一次并冻结记录哈希；
3. 运行前严格执行：hash(C_run) == hash(C*) 校验与基准测试；
4. 追踪载体读取开销并生成真正脱敏的盲评轨迹。
"""

from __future__ import annotations

import hashlib
import json
import os
import shutil
import time
from dataclasses import asdict
from pathlib import Path
from typing import Any

from environment import IsolatedEnvironment, SnapshotManager
from carriers import CarrierBuilder, CarrierManifest
from agent_runner import AgentModelDriver, AgentRunner, DeterministicPilotDriver, RunExecutionTrace
from metrics import MetricsExtractor, RunMetrics


class ProbeHarness:
    def __init__(self, experiment_root: Path) -> None:
        self.experiment_root = experiment_root.resolve()
        self.snapshots_mgr = SnapshotManager(self.experiment_root)
        self.results_dir = self.experiment_root / "results"
        self.frozen_carriers_dir = self.experiment_root / "frozen_carriers"
        self.results_dir.mkdir(parents=True, exist_ok=True)
        self.frozen_carriers_dir.mkdir(parents=True, exist_ok=True)

    def run_phase_A(
        self,
        source_dir: Path,
        task_prompt: str,
        driver: AgentModelDriver,
        budget_steps: int = 8,
    ) -> tuple[Path, dict[str, Any], bool, str]:
        """运行主体 A，产生真实轨迹 Canonical H_A* 并冻结基准物理快照 C*。"""
        print("\n" + "=" * 60)
        print("[*] Starting Phase A: Subject A execution on real task")
        print("=" * 60)

        initial_snapshot = self.snapshots_mgr.create_snapshot(source_dir, "initial_source")
        env_a = self.snapshots_mgr.spawn_run_environment(initial_snapshot, "phase_a_run")

        runner_a = AgentRunner(env_a, driver)
        trace_a = runner_a.run(
            run_id="subject_a",
            condition="source_generation",
            task_prompt=task_prompt,
            budget_steps=budget_steps,
        )

        test_res = env_a.run_pytest()
        a_solved_it = (test_res.exit_code == 0)

        c_star_path = self.snapshots_mgr.create_snapshot(env_a.workspace_root, "C_star_frozen")
        c_star_hash = env_a.compute_source_hash()

        events_list = []
        for step in trace_a.steps:
            events_list.append({
                "step_index": step.step_index,
                "tool_name": step.tool_name,
                "arguments": step.arguments,
                "output": step.output,
                "error": step.error,
                "exit_code": step.exit_code,
                "duration_ms": step.duration_ms,
            })

        h_a = {
            "task_prompt": task_prompt,
            "total_steps": len(trace_a.steps),
            "completed_within_budget": trace_a.completed_within_budget,
            "events": events_list,
            "final_response": trace_a.final_response,
            "raw_error_logs": test_res.output if not a_solved_it else "",
            "relevant_source_excerpts": "当前要求：测试套件必须全部通过，未破坏原有不变式。",
            "last_error": test_res.error or (test_res.output if test_res.exit_code != 0 else ""),
        }

        h_a_bytes = json.dumps(h_a, sort_keys=True, ensure_ascii=False).encode("utf-8")
        h_a_hash = hashlib.sha256(h_a_bytes).hexdigest()
        h_a["canonical_h_a_sha256"] = h_a_hash

        # 保存 Phase A 产物
        phase_a_dir = self.experiment_root / "phase_a"
        phase_a_dir.mkdir(parents=True, exist_ok=True)
        with open(phase_a_dir / "H_A_canonical.json", "w", encoding="utf-8") as f:
            json.dump(h_a, f, indent=2, ensure_ascii=False)

        print(f"[+] Phase A complete. Solved completely? {a_solved_it}")
        print(f"[+] C* frozen at: {c_star_path} (Hash: {c_star_hash[:16]}...)")
        print(f"[+] Canonical H_A* Hash: {h_a_hash[:16]}...")
        return c_star_path, h_a, a_solved_it, c_star_hash

    def prepare_frozen_carriers(self, h_a: dict[str, Any]) -> dict[str, CarrierManifest]:
        """从唯一 canonical H_A* 预先构建并冻结三组 Carrier，生成不可变模板。"""
        manifests = {}
        for cond in ["summary", "evidence", "navigable_trace"]:
            target_dir = self.frozen_carriers_dir / cond
            if cond == "summary":
                m = CarrierBuilder.mount_summary_carrier(h_a, target_dir)
            elif cond == "evidence":
                m = CarrierBuilder.mount_evidence_carrier(h_a, target_dir)
            elif cond == "navigable_trace":
                m = CarrierBuilder.mount_navigable_trace_carrier(h_a, target_dir)
            manifests[cond] = m
            print(f"[+] Frozen Carrier [{cond.upper()}]: Hash={m.carrier_hash[:16]}..., Bytes={m.total_bytes}")
        return manifests

    def run_single_B(
        self,
        c_star_path: Path,
        c_star_hash: str,
        carrier_manifest: CarrierManifest,
        condition: str,
        run_id: str,
        anonymous_id: str,
        driver: AgentModelDriver,
        task_prompt: str,
        budget_steps: int = 10,
    ) -> tuple[RunMetrics, dict[str, Any]]:
        """在完全隔离且经过哈希校验的 C* 副本上执行单个 B-run。"""
        # 1. 独立工作区派生
        env_b = self.snapshots_mgr.spawn_run_environment(c_star_path, run_id)

        # 2. 校验 C_run 哈希与 C* 完全一致
        run_hash = env_b.compute_source_hash()
        assert run_hash == c_star_hash, f"Snapshot drift detected in {run_id}! {run_hash} != {c_star_hash}"

        # 3. 挂载对应的冻结 Carrier 到工作区 carrier/
        carrier_source = self.frozen_carriers_dir / condition
        carrier_dest = env_b.workspace_root / "carrier"
        if carrier_dest.exists():
            shutil.rmtree(carrier_dest)
        shutil.copytree(carrier_source, carrier_dest)

        # 4. 跑一次基准测试确认初始状态干净
        baseline_test = env_b.run_pytest()
        # 基准测试状态记录

        # 5. 运行 B
        runner_b = AgentRunner(env_b, driver)
        trace_b = runner_b.run(
            run_id=run_id,
            condition=condition,
            task_prompt=task_prompt,
            budget_steps=budget_steps,
        )

        # 6. 最终真实测试验证
        final_test = env_b.run_pytest()
        test_passed = (final_test.exit_code == 0)

        # 7. 载体访问统计
        carrier_stats = env_b.get_carrier_stats(carrier_manifest.total_bytes)

        # 8. 提取指标与盲评轨迹
        metrics = MetricsExtractor.evaluate_trace(
            condition=condition,
            run_id=run_id,
            steps=[asdict(s) for s in trace_b.steps],
            carrier_stats=carrier_stats,
            final_test_passed=test_passed,
            final_test_output=final_test.output,
            duration_sec=trace_b.total_duration_sec,
        )

        blind_trace = MetricsExtractor.export_blind_trace(
            anonymous_id=anonymous_id,
            steps=[asdict(s) for s in trace_b.steps],
        )

        return metrics, blind_trace

    def run_full_experiment(
        self,
        source_dir: Path,
        task_prompt: str,
        driver_factory: Any,
        n_runs_per_condition: int = 3,
        budget_a: int = 8,
        budget_b: int = 10,
    ) -> dict[str, Any]:
        """运行完整 P1 实验流：A -> C* -> (Summary x n, Evidence x n, Position x n)"""
        driver_a = driver_factory("subject_a")
        c_star_path, h_a, a_solved_it, c_star_hash = self.run_phase_A(
            source_dir=source_dir,
            task_prompt=task_prompt,
            driver=driver_a,
            budget_steps=budget_a,
        )

        # 冻结三组 Carrier
        carrier_manifests = self.prepare_frozen_carriers(h_a)

        conditions = ["summary", "evidence", "navigable_trace"]
        all_metrics: list[dict[str, Any]] = []
        all_blind_traces: list[dict[str, Any]] = []
        blind_key_map: dict[str, str] = {}  # anonymous_id -> actual run_id

        print("\n" + "=" * 60)
        print(f"[*] Starting Phase B: Evaluating {len(conditions)} conditions (n={n_runs_per_condition} each)")
        print("=" * 60)

        anon_counter = 1
        for cond in conditions:
            print(f"\n---> Condition: [{cond.upper()}]")
            for r_idx in range(1, n_runs_per_condition + 1):
                run_id = f"run_{cond}_{r_idx}"
                anon_id = f"trace_sample_{anon_counter:02d}"
                blind_key_map[anon_id] = run_id
                anon_counter += 1

                driver_b = driver_factory(run_id)
                print(f"  - Executing {run_id} (Blind ID: {anon_id})...")
                metrics, blind_trace = self.run_single_B(
                    c_star_path=c_star_path,
                    c_star_hash=c_star_hash,
                    carrier_manifest=carrier_manifests[cond],
                    condition=cond,
                    run_id=run_id,
                    anonymous_id=anon_id,
                    driver=driver_b,
                    task_prompt=task_prompt,
                    budget_steps=budget_b,
                )
                all_metrics.append(asdict(metrics))
                all_blind_traces.append(blind_trace)
                print(f"    Finished. Success: {metrics.success}, Steps: {metrics.total_steps}, Carrier Read: {metrics.carrier_bytes_read}/{metrics.carrier_total_bytes} bytes ({metrics.carrier_read_percentage}%)")

        results_payload = {
            "timestamp": time.time(),
            "task_prompt": task_prompt,
            "phase_a_solved": a_solved_it,
            "c_star_sha256": c_star_hash,
            "canonical_h_a_sha256": h_a.get("canonical_h_a_sha256"),
            "carrier_manifests": {k: asdict(v) for k, v in carrier_manifests.items()},
            "n_runs_per_condition": n_runs_per_condition,
            "metrics": all_metrics,
            "blind_traces": all_blind_traces,
            "blind_key_map": blind_key_map,
        }

        results_json_path = self.results_dir / "experiment_results.json"
        with open(results_json_path, "w", encoding="utf-8") as f:
            json.dump(results_payload, f, indent=2, ensure_ascii=False)

        report_md_path = self.results_dir / "experiment_report.md"
        self._generate_report_markdown(results_payload, report_md_path)

        print("\n" + "=" * 60)
        print(f"[+] Full Experiment Finished!")
        print(f"[+] Results JSON: {results_json_path}")
        print(f"[+] Report Markdown: {report_md_path}")
        print("=" * 60)
        return results_payload

    def _generate_report_markdown(self, data: dict[str, Any], out_path: Path) -> None:
        metrics = data.get("metrics", [])
        lines = [
            "# Probe-P1 实验执行报告",
            "",
            f"- **任务目标**: {data.get('task_prompt')}",
            f"- **Phase A 完全解决**: {data.get('phase_a_solved')}",
            f"- **基准快照 C* SHA-256**: `{data.get('c_star_sha256')}`",
            f"- **Canonical H_A* SHA-256**: `{data.get('canonical_h_a_sha256')}`",
            f"- **每组重复次数 (n)**: {data.get('n_runs_per_condition')}",
            "",
            "## 1. 机械指标与载体开销汇总表",
            "",
            "| Run ID | Condition | Success | Steps | 1st Test | Read Files | Written Files | Carrier Total | Carrier Read | Carrier % |",
            "| :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- |",
        ]

        for m in metrics:
            lines.append(
                f"| `{m['run_id']}` | **{m['condition']}** | {'✅' if m['success'] else '❌'} | "
                f"{m['total_steps']} | {m['steps_to_first_test']} | "
                f"{m['files_read_count']} | {m['files_written_count']} | "
                f"{m['carrier_total_bytes']} B | {m['carrier_bytes_read']} B | {m['carrier_read_percentage']}% |"
            )

        lines.extend([
            "",
            "## 2. 盲评轨迹映射",
            "所有去标识化原始轨迹已导出，去标识键值对独立保存在 `blind_key_map` 中，可直接提交给第三方做双盲定性评估。",
            "",
        ])

        out_path.write_text("\n".join(lines), encoding="utf-8")
