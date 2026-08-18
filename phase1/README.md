# Phase 1：来源锚定回放探针

这一层先不实现 `ContextSnapshot`，也不预列记忆、主体或关系字段。`replay_probe.py` 只把一段原始来源逐字送入现有 Codex Runtime Adapter，并把以下事实输出或追加到 JSONL：

- 原文件路径、整文件 SHA-256 与行号；
- 实际输入全文；
- Codex `thread_id` 与本次 `turn_id`；
- 实际输出全文。

因此，同一段历史以后可以再次回放并得到新的 turn；旧输出仍然存在，不会被新解释覆盖。

示例：

```powershell
..\phase0\.venv\Scripts\python.exe replay_probe.py `
  "C:\path\to\raw.md" --start 183 --end 183 `
  --record "C:\path\to\probe.jsonl"
```

第一次 ex.2 探针故意只回放原句，不偷偷加入一套已经设计好的项目上下文。它要先暴露现有运行时会在哪里退化；后续再根据真实缺失增加最小来源查询。
