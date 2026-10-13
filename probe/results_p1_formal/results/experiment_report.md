# Probe-P1 实验执行报告

- **任务目标**: Cognos 第一发生场真实跨模块工程任务：
1. 目标：在 `cognos/web/server.py` 中实现参与说明 `participant_instructions.md` 的动态生效与查询机制。
2. 具体要求：
   a. 在 `web/server.py` 中新增 `GET /api/instructions` 接口，返回当前活跃说明的 `content` (正文字符串)、`path` (字符串路径) 及 `length` (字符数)；
   b. 重构参与说明读取逻辑，确保当磁盘上的说明文件内容被更新后，服务端及运行时（如 `GeminiRuntimeAdapter` / `CodexRuntimeAdapter`）在后续请求交互中能动态读取最新内容（而非仅在应用启动时读取一次静态缓存）；
   c. 在 `web/tests/test_server.py` 中编写单元测试覆盖上述两个功能，并保证既有测试套件全部通过。

- **Phase A 完全解决**: False
- **基准快照 C* SHA-256**: `eb3868a26d932131b842f3a04482cf63c7aa94918a20e8169e0ddb39fe0350c6`
- **Canonical H_A* SHA-256**: `05980533019de3529aef1abdc4fbdaaa5310d86d3b11441be946ff28bdc99213`
- **每组重复次数 (n)**: 3

## 1. 机械指标与载体开销汇总表

| Run ID | Condition | Success | Steps | 1st Test | Read Files | Written Files | Carrier Total | Carrier Read | Carrier % |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| `run_summary_1` | **summary** | ❌ | 8 | 7 | 4 | 0 | 752 B | 0 B | 0.0% |
| `run_summary_2` | **summary** | ❌ | 8 | -1 | 5 | 0 | 752 B | 0 B | 0.0% |
| `run_summary_3` | **summary** | ❌ | 8 | -1 | 5 | 0 | 752 B | 0 B | 0.0% |
| `run_evidence_1` | **evidence** | ✅ | 8 | -1 | 4 | 1 | 5005 B | 0 B | 0.0% |
| `run_evidence_2` | **evidence** | ❌ | 8 | 5 | 4 | 0 | 5005 B | 0 B | 0.0% |
| `run_evidence_3` | **evidence** | ❌ | 8 | 8 | 3 | 0 | 5005 B | 4943 B | 98.76% |
| `run_navigable_trace_1` | **navigable_trace** | ❌ | 8 | 8 | 4 | 0 | 34449 B | 0 B | 0.0% |
| `run_navigable_trace_2` | **navigable_trace** | ❌ | 8 | -1 | 4 | 0 | 34449 B | 0 B | 0.0% |
| `run_navigable_trace_3` | **navigable_trace** | ✅ | 8 | -1 | 4 | 2 | 34449 B | 0 B | 0.0% |

## 2. 盲评轨迹映射
所有去标识化原始轨迹已导出，去标识键值对独立保存在 `blind_key_map` 中，可直接提交给第三方做双盲定性评估。
