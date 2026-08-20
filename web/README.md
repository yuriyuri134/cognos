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

## Organization v0（第一可用切片）

在既有发生场之上增加普通组织/项目应用层（`web/org_store.py` + `web/org_api.py`）：

- 组织 → 项目 → 成员（human / AI；AI 成员绑定既有 participant_id，如 gemini/gemini-b/codex）。
- 项目内沟通复用既有 `ConversationService`（@定向、引用已有发生、各自 runtime continuity）。
- occurrence 可带可选 `project_id` 操作作用域；跨项目引用（quoted_occurrence_id 指向任何已有发生）始终成立，不假定一个发生永久只属于一个项目。
- SQLite 投影（`web/.state/org.db`，可被 `COGNOS_ORG_DB_PATH` 覆盖）只是 application projection/state，不是 raw 真相源；raw 真相源仍只有 Vault `第一发生场.jsonl`。
- Project/Member/Channel 等是普通软件对象，不是 Space/Subject/Relation 本体。

新端点：`/api/orgs`、`/api/orgs/{id}/projects`、`/api/orgs/{id}/members`、`/api/projects/{id}/messages`。

## 参与者运行时

| participant | runtime | 说明 |
|---|---|---|
| gemini / gemini-b | `GeminiRuntimeAdapter` | Google API；环境变量 `GEMINI_API_KEY`/`GEMINI_MODEL`；默认严格绑定（`COGNOS_GEMINI_ALLOW_FALLBACK=1` 才允许降级） |
| gemini-web | `GeminiRuntimeAdapter(search=True)` | 联网检索（Search Grounding）；`GEMINI_WEB=1` 时注册；回复带 `sources`（检索来源在消息下方回显）；注意 Gemini 配额（429）会同时影响它 |
| codex | `CodexRuntimeAdapter` | 本地 ChatGPT OAuth，线程可跨进程恢复 |
| deepseek | `DeepSeekRuntimeAdapter` | opencode-go / zen go 套餐；凭据在 `~/.dsh/.credentials.yaml` 或 `~/.pi/agent/auth.json`，可被 `DEEPSEEK_API_KEY`/`DEEPSEEK_BASE_URL`/`DEEPSEEK_MODEL`/`DEEPSEEK_TIMEOUT` 覆盖；只做对话，不执行工具 |

所有 AI 回复的 `runtime_ref` 都带 `model` 字段（实际响应模型，可识别降级）。
