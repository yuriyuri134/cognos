# Boundary Archive

外部来源的 ingress 投影/归档放在这里，与 `第一发生场.jsonl` 分开。

- `dsh/`：DSH session 的可读 projection；raw truth 仍是 `session.jsonl.zstd`。
- `manual/`：手工导入文件的 raw archive 与 `import.json`。

归档过 != 已进入当前 Cognos 发生场。
只有被 participant 显式面对/引用时，才在第一发生场留下 occurrence。
