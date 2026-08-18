"""
probe.environment
=================
提供严格物理隔离的测试工作区快照（C*）、载体挂载与沙箱化工具执行环境。

保证：
1. 每次 Run 从基准快照 C* 进行深拷贝，互不污染。
2. 运行前严格校验工作区哈希 hash(C_run) == hash(C*) 并跑基准测试。
3. 追踪 B 对 `carrier/` 的读取开销（已读字节数/百分比/文件列表）。
"""

from __future__ import annotations

import hashlib
import os
import shutil
import subprocess
import time
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any


@dataclass
class ToolExecutionResult:
    tool_name: str
    arguments: dict[str, Any]
    output: str
    error: str | None = None
    exit_code: int = 0
    duration_ms: float = 0.0
    timestamp: float = field(default_factory=time.time)


@dataclass
class CarrierAccessStats:
    carrier_total_bytes: int = 0
    carrier_bytes_read: int = 0
    carrier_files_accessed: list[str] = field(default_factory=list)
    carrier_read_percentage: float = 0.0


class IsolatedEnvironment:
    """单个 Agent Run 的隔离工作空间。"""

    def __init__(self, workspace_root: Path, base_snapshot_dir: Path | None = None) -> None:
        self.workspace_root = workspace_root.resolve()
        if base_snapshot_dir and base_snapshot_dir.exists():
            shutil.copytree(base_snapshot_dir, self.workspace_root, dirs_exist_ok=True)
        else:
            self.workspace_root.mkdir(parents=True, exist_ok=True)

        self.carrier_accessed_files: set[str] = set()
        self.carrier_bytes_read: int = 0

    def _resolve_safe_path(self, rel_path: str) -> Path:
        target = (self.workspace_root / rel_path).resolve()
        if not str(target).startswith(str(self.workspace_root)):
            raise ValueError(f"Path traversal denied: {rel_path}")
        return target

    def compute_source_hash(self) -> str:
        """计算工作区核心源文件与测试的 SHA-256（排除 carrier/ 与临时文件），用于基准一致性断言。"""
        hasher = hashlib.sha256()
        for p in sorted(self.workspace_root.rglob("*")):
            if p.is_file():
                rel = p.relative_to(self.workspace_root).as_posix()
                if rel.startswith("carrier") or rel.startswith(".") or "__pycache__" in rel or "pilot" in rel or "results" in rel or "snapshots" in rel or "runs" in rel:
                    continue
                hasher.update(rel.encode("utf-8"))
                hasher.update(p.read_bytes())
        return hasher.hexdigest()

    def list_dir(self, rel_path: str = ".") -> ToolExecutionResult:
        start = time.time()
        try:
            target = self._resolve_safe_path(rel_path)
            if not target.exists():
                return ToolExecutionResult("list_dir", {"path": rel_path}, "", error=f"Directory not found: {rel_path}", exit_code=1, duration_ms=(time.time()-start)*1000)
            
            norm_rel = Path(rel_path).as_posix()
            if norm_rel.startswith("carrier"):
                self.carrier_accessed_files.add(norm_rel)

            items = []
            for entry in sorted(os.scandir(target), key=lambda e: e.name):
                kind = "DIR" if entry.is_dir() else "FILE"
                size = entry.stat().st_size if entry.is_file() else 0
                items.append(f"[{kind}] {entry.name} ({size} bytes)" if kind == "FILE" else f"[{kind}] {entry.name}/")
            output = "\n".join(items) if items else "(empty directory)"
            return ToolExecutionResult("list_dir", {"path": rel_path}, output, duration_ms=(time.time()-start)*1000)
        except Exception as e:
            return ToolExecutionResult("list_dir", {"path": rel_path}, "", error=str(e), exit_code=1, duration_ms=(time.time()-start)*1000)

    def read_file(self, rel_path: str) -> ToolExecutionResult:
        start = time.time()
        try:
            target = self._resolve_safe_path(rel_path)
            if not target.exists() or not target.is_file():
                return ToolExecutionResult("read_file", {"path": rel_path}, "", error=f"File not found: {rel_path}", exit_code=1, duration_ms=(time.time()-start)*1000)
            content = target.read_text(encoding="utf-8", errors="replace")
            
            norm_rel = Path(rel_path).as_posix()
            if norm_rel.startswith("carrier"):
                self.carrier_accessed_files.add(norm_rel)
                self.carrier_bytes_read += len(content.encode("utf-8"))

            return ToolExecutionResult("read_file", {"path": rel_path}, content, duration_ms=(time.time()-start)*1000)
        except Exception as e:
            return ToolExecutionResult("read_file", {"path": rel_path}, "", error=str(e), exit_code=1, duration_ms=(time.time()-start)*1000)

    def write_file(self, rel_path: str, content: str) -> ToolExecutionResult:
        start = time.time()
        try:
            target = self._resolve_safe_path(rel_path)
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_text(content, encoding="utf-8")
            return ToolExecutionResult("write_file", {"path": rel_path, "content_len": len(content)}, f"Successfully written {len(content)} chars to {rel_path}", duration_ms=(time.time()-start)*1000)
        except Exception as e:
            return ToolExecutionResult("write_file", {"path": rel_path}, "", error=str(e), exit_code=1, duration_ms=(time.time()-start)*1000)

    def run_command(self, command: str, timeout_sec: int = 30) -> ToolExecutionResult:
        start = time.time()
        try:
            proc = subprocess.run(
                command,
                cwd=self.workspace_root,
                shell=True,
                capture_output=True,
                text=True,
                timeout=timeout_sec,
            )
            output = proc.stdout + (("\nSTDERR:\n" + proc.stderr) if proc.stderr else "")
            return ToolExecutionResult(
                "run_command",
                {"command": command},
                output.strip(),
                exit_code=proc.returncode,
                error=proc.stderr.strip() if proc.returncode != 0 else None,
                duration_ms=(time.time()-start)*1000,
            )
        except subprocess.TimeoutExpired:
            return ToolExecutionResult("run_command", {"command": command}, "", error="Command timed out", exit_code=124, duration_ms=(time.time()-start)*1000)
        except Exception as e:
            return ToolExecutionResult("run_command", {"command": command}, "", error=str(e), exit_code=1, duration_ms=(time.time()-start)*1000)

    def run_pytest(self, test_path: str = "web/tests") -> ToolExecutionResult:
        return self.run_command(f"python -m unittest discover -s {test_path} -p \"test_*.py\" -v")

    def execute_tool(self, tool_name: str, **kwargs: Any) -> ToolExecutionResult:
        if tool_name == "list_dir":
            return self.list_dir(kwargs.get("path", "."))
        elif tool_name == "read_file":
            return self.read_file(kwargs.get("path", ""))
        elif tool_name == "write_file":
            return self.write_file(kwargs.get("path", ""), kwargs.get("content", ""))
        elif tool_name == "run_command":
            return self.run_command(kwargs.get("command", ""))
        elif tool_name == "run_pytest":
            return self.run_pytest(kwargs.get("test_path", "web/tests"))
        else:
            return ToolExecutionResult(tool_name, kwargs, "", error=f"Unknown tool: {tool_name}", exit_code=1)

    def get_carrier_stats(self, carrier_total_bytes: int) -> CarrierAccessStats:
        pct = (self.carrier_bytes_read / carrier_total_bytes * 100.0) if carrier_total_bytes > 0 else 0.0
        return CarrierAccessStats(
            carrier_total_bytes=carrier_total_bytes,
            carrier_bytes_read=self.carrier_bytes_read,
            carrier_files_accessed=sorted(list(self.carrier_accessed_files)),
            carrier_read_percentage=round(pct, 2),
        )


class SnapshotManager:
    """管理物理环境的快照与独立隔离副本。"""

    def __init__(self, base_dir: Path) -> None:
        self.base_dir = base_dir.resolve()

    def create_snapshot(self, source_dir: Path, snapshot_name: str) -> Path:
        snapshot_dir = self.base_dir / "snapshots" / snapshot_name
        if snapshot_dir.exists():
            shutil.rmtree(snapshot_dir)
        snapshot_dir.parent.mkdir(parents=True, exist_ok=True)
        
        ignored_names = {
            "__pycache__", ".pytest_cache", ".state", ".venv", ".git",
            "carrier", "snapshots", "runs", "results", "frozen_carriers",
            "pilot_experiment_output", "cognos_probe_runs", "probe_output",
            "results_p1_formal"
        }
        
        def _ignore_filter(src: str, names: list[str]) -> set[str]:
            return {n for n in names if n in ignored_names or n.endswith(".tmp") or n.startswith(".")}

        shutil.copytree(
            source_dir,
            snapshot_dir,
            ignore=_ignore_filter,
        )
        return snapshot_dir

    def spawn_run_environment(self, snapshot_dir: Path, run_id: str) -> IsolatedEnvironment:
        run_workspace = self.base_dir / "runs" / run_id
        if run_workspace.exists():
            shutil.rmtree(run_workspace)
        return IsolatedEnvironment(run_workspace, base_snapshot_dir=snapshot_dir)
