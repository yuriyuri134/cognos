"""
probe.metrics
=============
从 Agent 的执行轨迹中提取完全客观、机械的指标，包含载体读取开销统计，并提供真正的盲评导出。
"""

from __future__ import annotations

import json
from dataclasses import asdict, dataclass, field
from typing import Any

from environment import CarrierAccessStats


@dataclass
class RunMetrics:
    condition: str
    run_id: str
    success: bool = False
    total_steps: int = 0
    steps_to_first_test: int = -1
    steps_to_first_fix: int = -1
    duplicate_failed_actions: int = 0
    files_read_count: int = 0
    files_written_count: int = 0
    commands_run_count: int = 0
    tests_run_count: int = 0
    
    # 载体信息量与访问开销
    carrier_total_bytes: int = 0
    carrier_bytes_read: int = 0
    carrier_read_percentage: float = 0.0
    carrier_files_accessed: list[str] = field(default_factory=list)

    total_duration_sec: float = 0.0
    final_test_output: str = ""
    tool_call_sequence: list[str] = field(default_factory=list)


class MetricsExtractor:
    """机械轨迹指标提取器。"""

    @staticmethod
    def evaluate_trace(
        condition: str,
        run_id: str,
        steps: list[dict[str, Any]],
        carrier_stats: CarrierAccessStats,
        final_test_passed: bool,
        final_test_output: str,
        duration_sec: float,
    ) -> RunMetrics:
        metrics = RunMetrics(
            condition=condition,
            run_id=run_id,
            success=final_test_passed,
            total_steps=len(steps),
            carrier_total_bytes=carrier_stats.carrier_total_bytes,
            carrier_bytes_read=carrier_stats.carrier_bytes_read,
            carrier_read_percentage=carrier_stats.carrier_read_percentage,
            carrier_files_accessed=carrier_stats.carrier_files_accessed,
            total_duration_sec=duration_sec,
            final_test_output=final_test_output[:500],
        )

        seen_failed_actions: set[str] = set()
        tests_passed_previously = False

        for idx, step in enumerate(steps, start=1):
            tool = step.get("tool_name", "")
            metrics.tool_call_sequence.append(tool)
            args = step.get("arguments", {})
            exit_code = step.get("exit_code", 0)

            if tool == "read_file":
                metrics.files_read_count += 1
            elif tool == "write_file":
                metrics.files_written_count += 1
            elif tool == "run_command":
                metrics.commands_run_count += 1
            elif tool == "run_pytest":
                metrics.tests_run_count += 1
                if metrics.steps_to_first_test == -1:
                    metrics.steps_to_first_test = idx
                if exit_code == 0 and not tests_passed_previously:
                    tests_passed_previously = True
                    if metrics.steps_to_first_fix == -1:
                        metrics.steps_to_first_fix = idx

            if exit_code != 0:
                action_signature = f"{tool}:{json.dumps(args, sort_keys=True)}"
                if action_signature in seen_failed_actions:
                    metrics.duplicate_failed_actions += 1
                else:
                    seen_failed_actions.add(action_signature)

        return metrics

    @staticmethod
    def export_blind_trace(anonymous_id: str, steps: list[dict[str, Any]]) -> dict[str, Any]:
        """输出彻底去标识化的轨迹，抹去 condition 名称与特征目录名。"""
        sanitized_steps = []
        for s in steps:
            args = dict(s.get("arguments", {}))
            # 统一匿名化处理，避免泄露组名
            if "path" in args and isinstance(args["path"], str):
                args["path"] = args["path"].replace("summary.txt", "context_blob.txt").replace("evidence.txt", "context_blob.txt")

            sanitized_steps.append({
                "step": s.get("step_index"),
                "action": s.get("tool_name"),
                "arguments": args,
                "exit_code": s.get("exit_code"),
                "duration_ms": s.get("duration_ms"),
                "output_preview": (s.get("output") or "")[:300],
            })
        return {
            "blind_trace_id": anonymous_id,
            "total_steps": len(steps),
            "trajectory": sanitized_steps,
        }
