"""
probe/test_harness.py
=====================
对 Probe-P1 实验装置（Harness）进行全量自检与回归测试。
"""

from __future__ import annotations

import json
import os
import shutil
import tempfile
import unittest
from pathlib import Path
import sys

PROBE_DIR = Path(__file__).resolve().parent
sys.path.insert(0, str(PROBE_DIR))

from environment import IsolatedEnvironment, SnapshotManager
from carriers import CarrierBuilder
from metrics import MetricsExtractor
from agent_runner import AgentRunner, DeterministicPilotDriver
from harness import ProbeHarness


class ProbeHarnessUnitTest(unittest.TestCase):
    def setUp(self) -> None:
        self.temp_dir = tempfile.TemporaryDirectory()
        self.root = Path(self.temp_dir.name)
        
        self.source_dir = self.root / "mock_cognos"
        self.source_dir.mkdir(parents=True)
        (self.source_dir / "web").mkdir(parents=True)
        (self.source_dir / "web" / "server.py").write_text("print('hello')", encoding="utf-8")
        (self.source_dir / "web" / "tests").mkdir(parents=True)
        (self.source_dir / "web" / "tests" / "test_sample.py").write_text(
            "import unittest\nclass T(unittest.TestCase):\n    def test_ok(self): pass\n",
            encoding="utf-8"
        )

    def tearDown(self) -> None:
        self.temp_dir.cleanup()

    def test_snapshot_isolation_and_hash_consistency(self) -> None:
        mgr = SnapshotManager(self.root / "exp_space")
        c_star = mgr.create_snapshot(self.source_dir, "C_star")

        env_b1 = mgr.spawn_run_environment(c_star, "run_b1")
        env_b2 = mgr.spawn_run_environment(c_star, "run_b2")

        # 校验克隆初始哈希完全一致
        h1 = env_b1.compute_source_hash()
        h2 = env_b2.compute_source_hash()
        self.assertEqual(h1, h2)

        # B1 修改文件
        env_b1.write_file("web/server.py", "print('modified by B1')")
        self.assertIn("modified by B1", env_b1.read_file("web/server.py").output)

        # B2 保证未被污染
        self.assertEqual("print('hello')", env_b2.read_file("web/server.py").output)
        self.assertEqual(h2, env_b2.compute_source_hash())

    def test_carrier_mounting_and_byte_tracking(self) -> None:
        h_a = {
            "task_prompt": "修复说明注入",
            "events": [
                {"step_index": 1, "tool_name": "read_file", "arguments": {"path": "web/server.py"}, "exit_code": 0},
                {"step_index": 2, "tool_name": "run_pytest", "arguments": {}, "exit_code": 1, "error": "AssertionError: failed"},
            ],
            "raw_error_logs": "AssertionError: failed",
            "relevant_source_excerpts": "不破坏单轮契约",
            "last_error": "AssertionError: failed",
        }

        mgr = SnapshotManager(self.root / "exp_space")
        c_star = mgr.create_snapshot(self.source_dir, "C_star")
        env_pos = mgr.spawn_run_environment(c_star, "run_pos")

        # 挂载 NavigableTrace 载体
        manifest = CarrierBuilder.mount_navigable_trace_carrier(h_a, env_pos.workspace_root / "carrier")
        self.assertEqual("navigable_trace", manifest.condition)
        self.assertTrue((env_pos.workspace_root / "carrier" / "index.json").exists())
        self.assertTrue((env_pos.workspace_root / "carrier" / "events" / "step_01_read_file.json").exists())

        # 读取 index.json 并验证读取开销追踪
        res = env_pos.read_file("carrier/index.json")
        stats = env_pos.get_carrier_stats(manifest.total_bytes)
        self.assertGreater(stats.carrier_bytes_read, 0)
        self.assertIn("carrier/index.json", stats.carrier_files_accessed)
        self.assertGreater(stats.carrier_read_percentage, 0.0)

    def test_full_pilot_harness_with_carriers_and_drift_assertion(self) -> None:
        harness = ProbeHarness(self.root / "full_exp")
        
        def driver_factory(run_id: str):
            return DeterministicPilotDriver()

        results = harness.run_full_experiment(
            source_dir=self.source_dir,
            task_prompt="Pilot 测试说明加载",
            driver_factory=driver_factory,
            n_runs_per_condition=2,
            budget_a=3,
            budget_b=4,
        )

        self.assertTrue(results["phase_a_solved"])
        self.assertEqual(2, results["n_runs_per_condition"])
        self.assertEqual(6, len(results["metrics"]))
        self.assertEqual(6, len(results["blind_traces"]))
        self.assertEqual(6, len(results["blind_key_map"]))

        # 检查报告落盘
        report_file = self.root / "full_exp" / "results" / "experiment_report.md"
        self.assertTrue(report_file.exists())
        content = report_file.read_text(encoding="utf-8")
        self.assertIn("Probe-P1 实验执行报告", content)
        self.assertIn("Carrier Total", content)


if __name__ == "__main__":
    unittest.main()
