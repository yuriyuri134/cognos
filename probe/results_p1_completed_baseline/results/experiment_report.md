# Probe-P1 实验执行报告

- **任务目标**: Cognos 第一发生场真实工程任务：
在 `cognos/web/server.py` 中为第一发生场增加消息单条详情与来源查询接口 `GET /api/messages/{message_id}`，返回指定单条消息的发生记录与 `runtime_ref`（若不存在返回 404）；并在 `web/tests/test_server.py` 中补充对应测试，确保现有测试套件全部通过。
- **Phase A 完全解决**: True
- **基准快照 C* SHA-256**: `0b8db1591201c896675e1e7af4da2eb79b82c53dd6fa86c0485b20584f4fb2c2`
- **Canonical H_A* SHA-256**: `124b210316c7f6531e5ae462191377ff414b42b9bf2f8feb21ee046398f716cc`
- **每组重复次数 (n)**: 3

## 1. 机械指标与载体开销汇总表

| Run ID | Condition | Success | Steps | 1st Test | Read Files | Written Files | Carrier Total | Carrier Read | Carrier % |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| `run_summary_1` | **summary** | ✅ | 8 | 6 | 3 | 0 | 446 B | 0 B | 0.0% |
| `run_summary_2` | **summary** | ✅ | 8 | 6 | 3 | 0 | 446 B | 0 B | 0.0% |
| `run_summary_3` | **summary** | ✅ | 8 | 7 | 3 | 1 | 446 B | 0 B | 0.0% |
| `run_evidence_1` | **evidence** | ✅ | 8 | 7 | 4 | 0 | 264 B | 0 B | 0.0% |
| `run_evidence_2` | **evidence** | ✅ | 8 | -1 | 3 | 1 | 264 B | 0 B | 0.0% |
| `run_evidence_3` | **evidence** | ✅ | 8 | 7 | 3 | 0 | 264 B | 0 B | 0.0% |
| `run_navigable_trace_1` | **navigable_trace** | ✅ | 8 | 6 | 3 | 0 | 44513 B | 0 B | 0.0% |
| `run_navigable_trace_2` | **navigable_trace** | ✅ | 7 | 7 | 3 | 0 | 44513 B | 0 B | 0.0% |
| `run_navigable_trace_3` | **navigable_trace** | ✅ | 8 | 6 | 4 | 0 | 44513 B | 0 B | 0.0% |

## 2. 盲评轨迹映射
所有去标识化原始轨迹已导出，去标识键值对独立保存在 `blind_key_map` 中，可直接提交给第三方做双盲定性评估。
