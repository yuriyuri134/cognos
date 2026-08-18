# Boundary：Cognos 与外部数字环境之间的第一层稳定边界

Boundary 是薄层。它只做机械同步、投影与投递，不重建 Participant / Occurrence / Task / Decision / Space / Relation / Memory。

当前只有两个方向：

- **Ingress**：外部 -> 可引用来源/投影归档（B1-B4）。扫描外部来源、生成可读投影/归档、更新 registry；绝不 append 第一发生场。
- **Egress/Return**：Handoff -> 外部执行（DSH）-> Result 回第一发生场（B5-B8）。生成 envelope、一次性执行（只创建，不 resume）、机械导出可观察结果、append-only 追加回第一发生场。

## B1-B8 薄层文件

| # | 名称 | 文件 | 说明 |
|---|------|------|------|
| B1 | Source Registry | `registry.py` | 只记机械事实：来源在哪、看到哪；不记 importance / belief / position / relation / memory / subject_level。 |
| B2 | DSH Source Adapter | `sources/dsh_source.py` | scan DSH session store -> 生成可读 projection/archive -> 更新 registry cursor。 |
| B3 | Generic Manual Import | `sources/manual_import.py` | 无本地 API 来源的统一入口：raw -> source identity -> archive -> registry。 |
| B4 | 投影/归档布局 | `archive/README.md` | `dsh/` 放 session 可读投影；`manual/` 放 raw archive 与 `import.json`。 |
| B5 | Handoff Envelope | `handoff/envelope.py` | 薄封装；实现见 `web/handoff/envelope.py`。 |
| B6 | DSH Runner | `handoff/dsh_runner.py` | 薄封装；实现见 `web/handoff/dsh_runner.py`。只创建 `dsh --profile headless "<task>"`，不 resume。 |
| B7 | Mechanical Result Exporter | `handoff/exporter.py` | 薄封装；实现见 `web/handoff/exporter.py`。机械提取 session log / git / test 证明，不让模型二次总结成功。 |
| B8 | Result Poster | `result/result_poster.py` | 薄封装；实现见 `web/handoff/result_poster.py`。`POST /api/handoff/result` 追加回第一发生场。 |

`handoff/__init__.py` 与 `result/__init__.py` 是 re-export 薄层；B5-B8 复用 `web/handoff` 已实现的 E1-E5，不重做。

## 硬边界

- **DSH raw log 不复制进 Vault**：`session.jsonl` / `session.jsonl.zstd` 只留在 DSH session store；Vault 只保存 `session_ref` 与机械提取的可观察结果（exit code、受影响文件、测试通过/失败状态）。
- 归档过 != 已进入第一发生场：只有被 participant 显式面对/引用时，才在第一发生场留下 occurrence。
- `.env`、API key、authorization 不得进入 envelope / result / Vault。
- `web/.state/` 下三个 thread 状态文件（`gemini_thread.json` / `gemini_b_thread.json` / `codex_thread.json`）不得合并或删除。
- runner 是独立进程；HTTP 请求不直接执行 dsh。

## 测试

```bash
cd /mnt/c/Users/lnp/Documents/Playground/cognos
PYTHONPATH=phase0:web <venv>/bin/python -m pytest -q -p no:cacheprovider web/tests phase0/tests phase1/tests test_phase1.py
```
