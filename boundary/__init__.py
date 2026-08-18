"""Boundary：Cognos 与外部数字环境之间的第一层稳定边界。

当前只有两个方向：
- Ingress：外部 -> 可引用来源/归档
- Egress/Return：Handoff -> 外部执行 -> Result 回第一发生场
"""

from .registry import SourceRegistry

__all__ = ["SourceRegistry"]
