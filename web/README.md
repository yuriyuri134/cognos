# Cognos 第一发生场

这是第一个可亲自进入的整体雏形，不是新的本体模型。它目前只实现：

- 浏览器里向 Codex 发言并取得回复；
- 使用既有 ChatGPT 登录，不使用模型 API Key；
- 通过同一 `thread_id` 跨请求、跨服务重启继续；
- 小林原文与 Codex 原文追加写入 Obsidian Vault；
- 每条 Codex 回复保留 `thread_id / turn_id`；
- Codex 失败时，已经发生的小林原文仍然留下。

它暂时不实现 Space 表、关系图、主体级别、固定位置、任务系统或 `ContextSnapshot`。右栏只是当前可核对的运行事实。

## 启动

首次安装网页依赖：

```powershell
..\phase0\.venv\Scripts\python.exe -m pip install -r requirements.txt
```

之后运行：

```powershell
.\start.ps1
```

或：

```powershell
..\phase0\.venv\Scripts\python.exe run.py --open
```

默认地址是 `http://127.0.0.1:8765`。

## 本地数据

- 对话原文：`Obsidian Vault/10 Projects/Cognos/运行数据/第一发生场.jsonl`
- Codex 恢复指针：`web/.state/codex_thread.json`
- 当前参与说明：`participant_instructions.md`

可用环境变量 `COGNOS_VAULT_PATH` 覆盖 Vault 根目录；测试隔离时还可用 `COGNOS_THREAD_STATE_PATH` 指定另一份 thread 指针。thread 状态只保存恢复指针，不保存认证凭据。

## 测试

```powershell
..\phase0\.venv\Scripts\python.exe -m unittest discover -s tests -v
```

## Execution Handoff E1-E5

- `handoff/`：E1 envelope、E2 DSH runner、E3 exporter、E4 result poster。
- `web/.state/handoffs/`：envelope projection/cache（不是真相源）。
- E5 由小林执行真实闭环，运行单见 `handoff/E5.md`。

```bash
PYTHONPATH=phase0:web <venv>/bin/python -m handoff.dsh_runner <envelope.json> --json
PYTHONPATH=phase0:web <venv>/bin/python -m handoff.exporter --session-log <session_log> ...
PYTHONPATH=phase0:web <venv>/bin/python -m handoff.result_poster <vault-payload.json>
```
