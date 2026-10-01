# return-change 三条件消融：指标重算与证据降级

2026-10-01。本文记录对 `work/ablation-return-change-*-2026-09-24.json` 的离线重算结果，以及因此对既有证据强度的修正。**不删除、不改写原始结果文件**；重算产物另存为 `work/ablation-return-change-metrics-recompute-2026-10-01.json`。

## 一、为什么要重算

2026-09-24 保存的 `metrics` 与当前 `web/tests/run_cognos_ablation.py` 的 `score_return_change()` 不一致。已核对的具体冲突：

- baseline 保存 `still_treats_presence_as_supported=true`，但其 move 原文写着"新的可验证事实已经否定了'灯亮→屋内有人'的暂定推断"。当前评分函数的排除词表含"否定"，按现行代码应返回 false。
- baseline 保存 `mentions_timer_mechanism=false`，而 move 原文含"定时灯"。
- baseline 保存 `move_tests_alternative_mechanism=null`，当前函数会产出该键。

结论：那批指标由**旧版评分函数**产出，且结果文件未保存当时的源码身份（无 commit、无源码 hash），因此无法追溯旧版逻辑。

## 二、重算方法

离线重算，**未重新调用任何模型**：

```
PYTHONPATH='phase0;web' phase0/.venv/Scripts/python.exe
  → 载入 web/tests/run_cognos_ablation.py 的 score_return_change()
  → 输入 response.run（原始 run 记录，未修改）
  → 输出与保存 metrics 的差异
```

## 三、重算结果

| 结果文件 | condition | runtime | 差异 |
|---|---|---|---|
| ablation-return-change-baseline-codex | baseline | CodexRuntimeAdapter | `mentions_timer_mechanism` false→true；`move_tests_alternative_mechanism` null→true；`still_treats_presence_as_supported` **true→false** |
| ablation-return-change-minimal-codex | minimal | CodexRuntimeAdapter | 无变化 |
| ablation-return-change-full-codex | full | CodexRuntimeAdapter | 无变化 |
| ablation-return-change-full-deepseek | full | openai-compatible | 保存值全为 null；重算后全为 false。**该次运行无有效指标**，对应 2026-09-19 记录的中转站断开故障，不得与 Codex 三组混算 |

重算后三组（Codex）的 `still_treats_presence_as_supported` 均为 false，`mentions_timer_mechanism` 均为 true。

## 四、证据强度修正

**撤回**：此前"baseline 仍把屋内有人当作成立，full 已否定"这一表述不成立。baseline 的实际 move 已经否定了旧推断。

**保留但降级**：三组之间仍存在的差别是——full 修订了 seed formation 身份（`seed_formation_revisions=1`）、resolved seed tension、move 引用了 seed ID、并提出"核对定时灯设定时间"（`move_checks_timer_schedule=true`）；baseline/minimal 没有这些。

但这一差别**不能归因于运行结构**，理由（2026-10-01 只读审计已核）：

1. 三条件操纵的是 `prompt_profile`，即**模型可见的文字输入**（baseline 5562 字符 / minimal 7401 / full 12758），不是运行结构。已重算 prompt SHA-256，与保存的 `context_prompt_hash` 一致。
2. 三组的运行结构相同：同样的两份文档、同样由 `seed_cognitive_event()` 写入的合成 Formation/Tension 事件、同样的 parser 与操作执行路径，首次调用时均无 adopted 组合、无 adopted source。
3. 只有 full 的 prompt 含 seed 稳定 ID 与修订语法说明。因此"full 会按 ID 修订"可由协议差异解释。
4. full 的"核对定时灯设定时间"是 `operations=[]` 的无操作 Move，只是提议，未实际核对。
5. baseline 的 `change` 操作使用了不受支持的 `action:"revise"` 而执行失败；不能排除短协议缺少操作语法的影响。

按本项目判据，这次消融属于 **D1 类（上下文组织与协议消融）**，不是 D2 类（结构干预）。它证明的是：给模型更完整的状态表示与修订入口，模型会更多地使用它们。**它没有证明结构承担了模型上下文之外的认知作用。**

## 五、当前唯一成立的 D1≠D2 证据

`web/.state/experiments/plasticity_running.json`（2026-09-09，隔离 harness `web/world_harness.py`）：

- D1（拒绝事实写成文字放进 question）：三个 target 全部真实可读，世界行为未变。
- D2（持久 AffordanceState，模型未被文字告知）：`t_deepseek_thread` 被 denied，provenance 为 `affordance history`。
- D2b（历史过期）：恢复真实读取。

局限：AffordanceState 由实验脚本硬编码写入（`store.record(...)`），**不是活动中提出并被采纳的构造**；且该实验运行在隔离 harness，不在真实 activity runtime 上；当日源码身份未核实（`phase0/deepseek_runtime.py`、`codex_runtime.py` 现已修改）。

## 六、由此确定的下一步

需要一次在**真实 activity runtime** 上的严格 D1 vs D2 对照，干预点选已验证可执行且模型未被文字告知的通路——`adopt_source`（改变项目取材表达式）：

- 同一构造，一组只作为自然语言文字提供（D1）；
- 一组真的 adopt_source 进取材位置（D2）；
- 比较实际取材结果是否不同；
- release_source 后是否恢复（D2b）。

若 D2 ≈ D1，则当前所谓结构作用尚未产生，应停下来重新设计，而不是继续增加机制。

## 七、复现纪律缺口（须补）

按 `docs/Cognos-v3-行动思想.md` 第 8 节，实验结果要可复现还缺：

- 实验时源码基线身份：commit **加上**未跟踪源码的完整快照或补丁及 hash（hash 单独存在不能恢复源码）。
- 源码版本与结果文件的绑定；本次已出现评分代码与保存指标不一致，正说明缺少这一绑定。
- 可还原的依赖与 SDK/CLI 版本、调用预算、运行配置。
- 实际模型身份：三份 Codex 结果的 `actual_model` 均为 null；Codex 内部附加上下文未捕获，须在报告中明确边界。
