# Execution Handoff E1–E5

薄层，不重建 Participant / Occurrence / Task / Decision / Space / Relation / Memory。

## 文件

- `envelope.py` — E1：最小 Handoff envelope；occurrence 投影带 provenance，明确不是真相源。
- `dsh_runner.py` — E2：独立 CLI，只创建 `dsh --profile headless "<task>"`，不 resume。
- `exporter.py` — E3：机械提取 session log、git provenance、test provenance；不让模型二次总结成功。
- `result_poster.py` — E4：把 Vault-safe payload 通过 `POST /api/handoff/result` 追加回第一发生场。

## 使用

```bash
cd /mnt/c/Users/lnp/Documents/Playground/cognos

# 1) 小林在网页或 API 创建 Handoff H，获得 envelope_path
#    POST /api/handoff/create

# 2) DSH runner（独立进程，不在 HTTP 内执行）
PYTHONPATH=phase0:web <venv>/bin/python -m handoff.dsh_runner \
  web/.state/handoffs/<handoff_id>.json --json

# 3) 导出可观察 Result
PYTHONPATH=phase0:web <venv>/bin/python -m handoff.exporter \
  --session-log <session_log_path> \
  --working-directory /mnt/c/Users/lnp/Documents/Playground/cognos \
  --handoff-id <handoff_id> \
  --related-occurrence-id <A的occurrence_id> \
  --vault-payload-out /tmp/cognos-result-<handoff_id>.json

# 4) 回第一发生场（FastAPI 需已重启或运行中）
PYTHONPATH=phase0:web <venv>/bin/python -m handoff.result_poster \
  /tmp/cognos-result-<handoff_id>.json \
  --server-url http://127.0.0.1:8765
```

## DSH_HOME

当前 DSH session 运行在 workspace-write 沙箱内，通常不能写 `~/.dsh/profiles`。
runner 默认尊重 `$DSH_HOME`；需要时可显式指定：

```bash
--dsh-home /tmp/cognos-dsh-home
```

不要把 `~/.dsh` 只读误判为 Playground 不可写。

## 边界

- runner 是独立脚本；HTTP 请求不直接执行 dsh。
- Vault 只保存 `session_ref` 和机械提取的可观察结果，不复制完整 tool log。
- `.env`、API key、authorization 不得进入 envelope / result / Vault。
- `web/.state/` 下三个 thread 状态文件不得合并或删除。
