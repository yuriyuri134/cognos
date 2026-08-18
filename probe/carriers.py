"""
probe.carriers
==============
实现三种载体 Carrier(H_A) 的机械、可复核、同构挂载构造。

核心约束：
1. 三组以完全相同的目录形式挂载在隔离工作区根目录的 `carrier/` 下。
2. Summary 组：`carrier/summary.txt`（冻结压缩摘要）。
3. Evidence 组：`carrier/evidence.txt`（冻结的真实客观报错与事实约束，无解法建议）。
4. Position 组：`carrier/` 下的可导航事件、日志与差异文件结构（无任何 AI 意义标签）。
5. 所有 Carrier 均由唯一的 Canonical H_A* 冻结生成，并记录各自的 SHA-256 哈希。
"""

from __future__ import annotations

import hashlib
import json
from dataclasses import dataclass
from pathlib import Path
from typing import Any


@dataclass
class CarrierManifest:
    condition: str
    carrier_hash: str
    total_bytes: int
    files_manifest: dict[str, int]  # rel_path -> byte_size


class CarrierBuilder:
    """构建三种对比组的物理挂载文件结构。"""

    @staticmethod
    def _compute_dir_hash(carrier_dir: Path) -> tuple[str, int, dict[str, int]]:
        files_manifest: dict[str, int] = {}
        hasher = hashlib.sha256()
        total_bytes = 0
        
        for p in sorted(carrier_dir.rglob("*")):
            if p.is_file():
                rel = p.relative_to(carrier_dir).as_posix()
                content = p.read_bytes()
                size = len(content)
                total_bytes += size
                files_manifest[rel] = size
                hasher.update(rel.encode("utf-8"))
                hasher.update(content)
                
        return hasher.hexdigest(), total_bytes, files_manifest

    @classmethod
    def mount_summary_carrier(cls, h_a: dict[str, Any], target_carrier_dir: Path) -> CarrierManifest:
        """挂载 Summary 组：carrier/summary.txt"""
        if target_carrier_dir.exists():
            import shutil
            shutil.rmtree(target_carrier_dir)
        target_carrier_dir.mkdir(parents=True, exist_ok=True)

        summary_text = h_a.get("summary_text", "")
        if not summary_text:
            events = h_a.get("events", [])
            tools_used = [ev.get("tool_name") for ev in events if "tool_name" in ev]
            files_touched = sorted(list(set(
                ev.get("arguments", {}).get("path") for ev in events if ev.get("arguments", {}).get("path")
            )))
            last_error = h_a.get("last_error", "无明显报错")
            summary_text = (
                f"【前序工作摘要】\n"
                f"开发者 A 在当前工作区进行了尝试。修改或查看了以下文件：{', '.join(files_touched)}。\n"
                f"调用了工具序列：{', '.join(tools_used)}。\n"
                f"最终状态：测试未全部通过，最后遇到的主要现象/报错为：{last_error[:300]}。\n"
                f"当前代码处于半完成状态，请继续完成任务。\n"
            )

        summary_file = target_carrier_dir / "summary.txt"
        summary_file.write_text(summary_text, encoding="utf-8")

        c_hash, total_bytes, manifest = cls._compute_dir_hash(target_carrier_dir)
        return CarrierManifest(condition="summary", carrier_hash=c_hash, total_bytes=total_bytes, files_manifest=manifest)

    @classmethod
    def mount_evidence_carrier(cls, h_a: dict[str, Any], target_carrier_dir: Path) -> CarrierManifest:
        """挂载 Evidence 组：carrier/evidence.txt（客观报错与事实约束，无解法）"""
        if target_carrier_dir.exists():
            import shutil
            shutil.rmtree(target_carrier_dir)
        target_carrier_dir.mkdir(parents=True, exist_ok=True)

        raw_error = h_a.get("raw_error_logs", "").strip() or h_a.get("last_error", "").strip()
        relevant_constraint = h_a.get("relevant_source_excerpts", "保持原有单元测试全部通过。").strip()

        evidence_text = (
            f"【客观事实与原始证据（Evidence 提取）】\n\n"
            f"1. A 退出时最终失败的完整测试输出：\n```\n{raw_error}\n```\n\n"
            f"2. 当前任务涉及的核心真实行为约束：\n```\n{relevant_constraint}\n```\n"
        )

        evidence_file = target_carrier_dir / "evidence.txt"
        evidence_file.write_text(evidence_text, encoding="utf-8")

        c_hash, total_bytes, manifest = cls._compute_dir_hash(target_carrier_dir)
        return CarrierManifest(condition="evidence", carrier_hash=c_hash, total_bytes=total_bytes, files_manifest=manifest)

    @classmethod
    def mount_navigable_trace_carrier(cls, h_a: dict[str, Any], target_carrier_dir: Path) -> CarrierManifest:
        """挂载 NavigableTrace 组：carrier/ 可导航事件、日志与差异文件结构（无任何 AI 意义标签，验证模型外可重达历史）"""
        if target_carrier_dir.exists():
            import shutil
            shutil.rmtree(target_carrier_dir)
        target_carrier_dir.mkdir(parents=True, exist_ok=True)

        events_dir = target_carrier_dir / "events"
        logs_dir = target_carrier_dir / "logs"
        diffs_dir = target_carrier_dir / "diffs"
        events_dir.mkdir(parents=True, exist_ok=True)
        logs_dir.mkdir(parents=True, exist_ok=True)
        diffs_dir.mkdir(parents=True, exist_ok=True)

        events = h_a.get("events", [])
        manifest_entries = []

        for idx, ev in enumerate(events, start=1):
            step_file = events_dir / f"step_{idx:02d}_{ev.get('tool_name', 'action')}.json"
            step_file.write_text(json.dumps(ev, indent=2, ensure_ascii=False), encoding="utf-8")
            manifest_entries.append({
                "step": idx,
                "tool": ev.get("tool_name"),
                "file": f"events/{step_file.name}",
                "exit_code": ev.get("exit_code", 0),
            })

        (target_carrier_dir / "index.json").write_text(
            json.dumps({"total_events": len(events), "events": manifest_entries}, indent=2, ensure_ascii=False),
            encoding="utf-8"
        )

        raw_logs = h_a.get("raw_error_logs", "") or h_a.get("last_error", "")
        if raw_logs:
            (logs_dir / "last_test_output.log").write_text(raw_logs, encoding="utf-8")

        diff_content = h_a.get("git_diff", "")
        if diff_content:
            (diffs_dir / "changes_by_A.patch").write_text(diff_content, encoding="utf-8")

        c_hash, total_bytes, manifest = cls._compute_dir_hash(target_carrier_dir)
        return CarrierManifest(condition="navigable_trace", carrier_hash=c_hash, total_bytes=total_bytes, files_manifest=manifest)

    # 保持兼容别名
    mount_position_carrier = mount_navigable_trace_carrier

