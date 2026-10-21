"""
probe.agent_runner
==================
驱动 Agent 在隔离工作区中执行对称的 Tool-Use 循环，严格受限于 step 预算。
所有组使用完全相同的初始提示词模板与相同的标准通用工具集。
"""

from __future__ import annotations

import json
import time
from dataclasses import dataclass, field
from typing import Any, Protocol

from environment import IsolatedEnvironment, ToolExecutionResult


@dataclass
class AgentStepRecord:
    step_index: int
    tool_name: str
    arguments: dict[str, Any]
    output: str
    error: str | None = None
    exit_code: int = 0
    duration_ms: float = 0.0
    timestamp: float = field(default_factory=time.time)


@dataclass
class RunExecutionTrace:
    run_id: str
    condition: str
    task_prompt: str
    steps: list[AgentStepRecord] = field(default_factory=list)
    final_response: str = ""
    total_duration_sec: float = 0.0
    completed_within_budget: bool = True


class AgentModelDriver(Protocol):
    """模型驱动接口。支持接入 Gemini 或确定性驱动。"""

    def decide_next_action(
        self,
        system_prompt: str,
        messages: list[dict[str, Any]],
        available_tools: list[dict[str, Any]],
    ) -> tuple[str, dict[str, Any], str]:
        """返回 (tool_name, arguments, thought_text)"""


class DeterministicPilotDriver:
    """用于 Pilot 验证实验装置的确定性驱动器。"""

    def __init__(self, actions_sequence: list[tuple[str, dict[str, Any]]] | None = None) -> None:
        self.actions_sequence = actions_sequence or [
            ("list_dir", {"path": "."}),
            ("list_dir", {"path": "carrier"}),
            ("read_file", {"path": "web/server.py"}),
            ("run_pytest", {"test_path": "web/tests"}),
        ]
        self._step = 0

    def decide_next_action(
        self,
        system_prompt: str,
        messages: list[dict[str, Any]],
        available_tools: list[dict[str, Any]],
    ) -> tuple[str, dict[str, Any], str]:
        if self._step < len(self.actions_sequence):
            tool, args = self.actions_sequence[self._step]
            self._step += 1
            return tool, args, f"Executing scheduled action {tool}"
        return "finish", {}, "All planned pilot actions completed."


class AgentRunner:
    """Agent 执行器。对所有组提供完全对称的工具集与提示词入口。"""

    AVAILABLE_TOOLS = [
        {"name": "list_dir", "description": "列出指定目录下的文件和子目录", "parameters": {"path": "string"}},
        {"name": "read_file", "description": "读取指定相对路径的文件内容", "parameters": {"path": "string"}},
        {"name": "write_file", "description": "写入或覆盖指定相对路径的文件内容", "parameters": {"path": "string", "content": "string"}},
        {"name": "run_command", "description": "在工作区根目录执行 Shell 命令", "parameters": {"command": "string"}},
        {"name": "run_pytest", "description": "运行单元测试套件并收集结果", "parameters": {"test_path": "string"}},
        {"name": "finish", "description": "声明任务已完成并给出最终总结", "parameters": {}},
    ]

    UNIFORM_SYSTEM_PROMPT = (
        "遵循理解优先协议：在每次 tool use 之前，先执行理解与调查动作，确保获得充分上下文后再采取修改行为。\n"
        "\n"
        "你是一个负责定位并实现真实工程任务的自主智能体。\n"
        "你可以使用通用工具查看目录、读取文件、修改代码、运行测试。\n"
        "若当前工作区已挂载前序上下文目录 `carrier/`，你可以通过 list_dir / read_file 查阅历史发生与线索。\n"
        "请务必深入代码库进行实质性的调查、修改或测试验证，不要在没有执行实际操作前提前结束。\n"
    )

    def __init__(self, env: IsolatedEnvironment, driver: AgentModelDriver) -> None:
        self.env = env
        self.driver = driver

    def run(
        self,
        run_id: str,
        condition: str,
        task_prompt: str,
        budget_steps: int = 10,
    ) -> RunExecutionTrace:
        start_time = time.time()
        trace = RunExecutionTrace(run_id=run_id, condition=condition, task_prompt=task_prompt)

        messages: list[dict[str, Any]] = [
            {"role": "user", "content": task_prompt}
        ]

        for step_idx in range(1, budget_steps + 1):
            tool_name, args, thought = self.driver.decide_next_action(
                self.UNIFORM_SYSTEM_PROMPT, messages, self.AVAILABLE_TOOLS
            )

            if tool_name == "finish":
                trace.final_response = thought
                break

            result: ToolExecutionResult = self.env.execute_tool(tool_name, **args)
            step_record = AgentStepRecord(
                step_index=step_idx,
                tool_name=tool_name,
                arguments=args,
                output=result.output,
                error=result.error,
                exit_code=result.exit_code,
                duration_ms=result.duration_ms,
            )
            trace.steps.append(step_record)

            messages.append({
                "role": "assistant",
                "content": json.dumps(
                    {"thought": thought, "tool": tool_name, "arguments": args},
                    ensure_ascii=False,
                ),
            })
            messages.append({
                "role": "user",
                "content": f"Tool Output (exit {result.exit_code}):\n{result.output}\n{result.error or ''}",
            })
        else:
            trace.completed_within_budget = False
            trace.final_response = f"Budget exhausted ({budget_steps} steps limit reached)."

        trace.total_duration_sec = time.time() - start_time
        return trace
