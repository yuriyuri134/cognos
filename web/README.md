# Cognos 第一发生场

这是第一个可亲自进入的整体雏形，不是新的本体模型。它目前提供两条互相连接的入口：普通组织沟通，以及从材料继续展开的 Activity。

- 浏览器里向 Codex 发言并取得回复；
- Web 协作者使用 Cognos 账号登录；Codex 模型运行时仍使用既有 ChatGPT 登录；
- 通过同一 `thread_id` 跨请求、跨服务重启继续；
- 小林原文与 Codex 原文追加写入 Obsidian Vault；
- 每条 Codex 回复保留 `thread_id / turn_id`；
- Codex 失败时，已经发生的小林原文仍然留下。
- Activity 中保存可选的 Formation、薄 Move、真实执行结果、Context Snapshot 与外部检索遭遇；检索来源和正文提取状态作为材料保留。

它暂时不实现 Space 表、关系图、主体级别、固定位置、全局知识图谱或自动循环。Activity、组织项目和检索材料都是现阶段的应用承载，不宣称已经实现最终认识本体。右栏只是当前可核对的运行事实。

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

供同一局域网的同学访问时，从 `web/` 目录显式选择监听地址：

```powershell
.\start.ps1 --host 0.0.0.0 --port 8765
# 或不自动打开浏览器：
..\phase0\.venv\Scripts\python.exe run.py --host 0.0.0.0 --port 8765
```

同学在浏览器打开 `http://<这台电脑的局域网 IPv4 地址>:8765`，然后注册或登录。
`0.0.0.0` 是服务监听地址，本机自动打开浏览器时使用 `127.0.0.1`。
两端需要网络互通；若被 Windows 防火墙拦截，需由机器管理员允许相应端口。
本批没有更改默认 host、没有配置防火墙、没有提供 HTTPS 或公网部署，也没有完成另一台电脑的连接验证。

## 登录与多人协作边界

- 公开：静态页面、health/bootstrap、业务 GET（项目、历史、成员主页等）、注册/登录/注销、`/api/auth/me`。
- 需会话：所有业务 POST/PATCH（消息、探究、成员修改、工作项、Activity、handoff 创建和结果回流）；账号目录 `/api/auth/accounts` 也需登录。
- 当前是开放协作试用：任何已登录账号都可参与现有项目。尚未实现邀请凭证、组织/项目权限隔离、私有读取、注册限流或密码找回。因此登录不能被解释为项目隐私或角色授权。
- 普通消息、项目/频道消息、探究及 Activity 操作的作者/操作者来自会话账号的 `display_name`；请求体中的 `author` 不决定身份。AI 回复仍归 AI；外部执行结果保留代理作者，并记录登录提交者。
- 新 raw 发生附带 `author_account_id`。成员投影按稳定账号 ID 关联；旧 raw 和旧无账号成员不被同名注册者认领或回写。
- 创建组织/项目及首次项目发言或 Activity 操作会登记当前协作者。项目中央“成员”页签可添加已注册账号或现有组织成员，并打开主页查看职责、资料、项目与发言；窄屏无需右栏入口。
- 账号库为 `web/.state/auth.db`（可用 `COGNOS_AUTH_DB_PATH` 指定）。它是登录应用状态，不是 raw 真相源或 Subject。密码存 PBKDF2 哈希，session token 仅通过 HttpOnly、SameSite=Lax cookie 传递，数据库只保存 token 哈希；会话有效期 14 天，注销/轮换撤销旧会话。
- 默认 HTTP 试用不设 Secure。未来部署实际具备 HTTPS 时才可设置 `COGNOS_AUTH_COOKIE_SECURE=1`；这个开关本身不提供 HTTPS。

## 本地数据

- 对话原文：`Obsidian Vault/10 Projects/Cognos/运行数据/第一发生场.jsonl`
- Codex 恢复指针：`web/.state/codex_thread.json`
- 当前参与说明：`participant_instructions.md`

可用环境变量 `COGNOS_VAULT_PATH` 覆盖 Vault 根目录；测试隔离时还可用 `COGNOS_THREAD_STATE_PATH` 指定另一份 thread 指针。thread 状态只保存恢复指针，不保存认证凭据。

## 测试

```powershell
$testRoot = Join-Path $env:TEMP ('cognos-tests-' + [guid]::NewGuid().ToString('N'))
$env:COGNOS_VAULT_PATH = Join-Path $testRoot 'vault'
$env:COGNOS_THREAD_STATE_DIR = Join-Path $testRoot 'state'
$env:COGNOS_AUTH_DB_PATH = Join-Path $testRoot 'auth.db'
$env:COGNOS_ORG_DB_PATH = Join-Path $testRoot 'org.db'
$env:COGNOS_INQUIRY_DB_PATH = Join-Path $testRoot 'inquiry.db'
$env:COGNOS_HANDOFF_DIR = Join-Path $testRoot 'handoffs'
$env:COGNOS_SITUATION_DIR = Join-Path $testRoot 'situations'
$env:PYTHONPATH = '..\phase0;.'
..\phase0\.venv\Scripts\python.exe -m pytest -q -p no:cacheprovider tests ..\phase0\tests
```

使用独立终端运行测试；关闭该终端后再正常启动服务，避免测试环境变量被误用于真实服务。
测试配置各自使用临时数据，上述环境变量还隔离了 `server` 导入时创建的默认 app。

## Execution Handoff E1-E5

- `handoff/`：E1 envelope、E2 DSH runner、E3 exporter、E4 result poster。
- `web/.state/handoffs/`：envelope projection/cache（不是真相源）。
- E5 由小林执行真实闭环，运行单见 `handoff/E5.md`。

```bash
PYTHONPATH=phase0:web <venv>/bin/python -m handoff.dsh_runner <envelope.json> --json
PYTHONPATH=phase0:web <venv>/bin/python -m handoff.exporter --session-log <session_log> ...
PYTHONPATH=phase0:web <venv>/bin/python -m handoff.result_poster <vault-payload.json> --username <Cognos账号>
```

E4 从终端隐式读取密码，登录后的 cookie 只保留在 HTTP client 内存中，回流后注销；
密码不放入命令行，token 不进入结果 payload。已有未登录 HTTP 写入脚本必须先登录并保留 cookie，匿名结果回流返回 401。

## Organization v0（第一可用切片）

在既有发生场之上增加普通组织/项目应用层（`web/org_store.py` + `web/org_api.py`）：

- 组织 → 项目 → 成员（human / AI；AI 成员绑定既有 participant_id，如 gemini/gemini-b/codex）。
- 项目内沟通复用既有 `ConversationService`（@定向、引用已有发生、各自 runtime continuity）。
- occurrence 可带可选 `project_id` 操作作用域；跨项目引用（quoted_occurrence_id 指向任何已有发生）始终成立，不假定一个发生永久只属于一个项目。
- SQLite 投影（`web/.state/org.db`，可被 `COGNOS_ORG_DB_PATH` 覆盖）只是 application projection/state，不是 raw 真相源；raw 真相源仍只有 Vault `第一发生场.jsonl`。
- Project/Member/Channel 等是普通软件对象，不是 Space/Subject/Relation 本体。

新端点：`/api/orgs`、`/api/orgs/{id}/projects`、`/api/orgs/{id}/members`、`/api/projects/{id}/messages`。

## 探究（question + 可再进入的多次尝试）

Situated Cognition 的下一个小 delta：让同一问题上的多次展开尝试接成一个可再进入的过程。

- 项目内可创建探究（question 原话成为一条带 `inquiry_id` 的发生）；
- 每次尝试 = 一次 AI 调用，输入带：探究问题 + 之前所有尝试的 working_report + 当前处境；
- 尝试信封要求 `working_report`（触及/改变/未展开/下一次），存入投影并出现在下一次尝试的输入里——后一轮承接前一轮，不从头重讲；
- 停下 ≠ 结束：状态保留在 `web/.state/inquiry.db`（应用投影），可随时再次进入；
- raw occurrence 仍只进第一发生场.jsonl（带 inquiry_id/attempt_no/working_report provenance）。

探究/尝试是普通应用层对象，不是新本体；权威关系不变。端点：`/api/projects/{id}/inquiries`、`/api/inquiries/{id}`、`/api/inquiries/{id}/attempts`。

## 参与者运行时

| participant | runtime | 说明 |
|---|---|---|
| gemini / gemini-b | `GeminiRuntimeAdapter` | Google API；环境变量 `GEMINI_API_KEY`/`GEMINI_MODEL`；默认严格绑定（`COGNOS_GEMINI_ALLOW_FALLBACK=1` 才允许降级） |
| gemini-web | `GeminiRuntimeAdapter(search=True)` | 联网检索（Search Grounding）；`GEMINI_WEB=1` 时注册；回复带 `sources`（检索来源在消息下方回显）；注意 Gemini 配额（429）会同时影响它 |
| codex | `CodexRuntimeAdapter` | 本地 ChatGPT OAuth，线程可跨进程恢复 |
| deepseek | `DeepSeekRuntimeAdapter` | opencode-go / zen go 套餐；凭据在 `~/.dsh/.credentials.yaml` 或 `~/.pi/agent/auth.json`，可被 `DEEPSEEK_API_KEY`/`DEEPSEEK_BASE_URL`/`DEEPSEEK_MODEL`/`DEEPSEEK_TIMEOUT` 覆盖；只做对话，不执行工具 |

能可靠取得实际模型时，AI 回复的 `runtime_ref` 才带 `model` 字段；未知时不猜测。

## 可选 Pi participant

Pi 是暂时的异构 runtime 探针，不代表 Cognos 最终本体。默认不注册；启用后固定
participant id 为 `pi`、显示名为 `Pi`，capabilities 只有 `conversation`。
已有默认参与者和默认目标不变，注入自定义 registry/runtime 的测试模式不自动加 Pi。

| 环境变量 | 默认值 / 含义 |
| --- | --- |
| `COGNOS_PI_ENABLED` | 未设置；只有 `1` 才注册 |
| `COGNOS_PI_COMMAND` | `pi`；单个可执行文件名或完整路径，不接受 shell 参数串 |
| `COGNOS_PI_CWD` | Cognos 仓库根目录；显式传给子进程 |
| `COGNOS_PI_SESSION_DIR` | `COGNOS_THREAD_STATE_DIR/pi-sessions/`；仅显式配置才允许改位置 |
| `COGNOS_PI_TIMEOUT` | `120` 秒；正数，RPC 调用时限，另有最多两次 1 秒的退出宽限 |
| `COGNOS_PI_MODEL` | 未指定；设置时传给 Pi 的 `--model`，不猜测实际响应模型 |

普通绑定文件为 `COGNOS_THREAD_STATE_DIR/pi_thread.json`，仅保存 session id/path。
默认 `COGNOS_THREAD_STATE_DIR` 是 `web/.state`。session JSONL 由 Pi 自己管理；
adapter 不读取或写入 `auth.json`，不复用 `~/.pi/agent/sessions`。如明确配置自定义
session 目录，保存/恢复路径仍限制在该目录内，不自动扫描或导入其他 session。
Activity 每次 `--no-session`，不读写普通绑定。不存在 Pi 时应用仍可启动，调用返回 502。

示例（从仓库根目录启动，先在 Pi 自身完成配置）：

```powershell
$env:COGNOS_PI_ENABLED='1'
$env:COGNOS_PI_CWD=(Get-Location).Path
# 可选：$env:COGNOS_PI_COMMAND='C:\path\to\pi.cmd'
# 可选：$env:COGNOS_PI_MODEL='provider/model-id'
& phase0/.venv/Scripts/python.exe web/run.py
```

Pi 工具和扩展固定禁用；普通消息无法借此执行代码，真实执行仍走 handoff/DSH。
模型未知时 profile/Activity 保留 `None`；turn 引用是 adapter 生成的 `turn_<uuid>`。
请求审计记录协议、命令和正文摘要，不复制凭据与原始 stderr。完整边界、离线回归
及隔离真实验收命令见 [Phase 0 Pi 说明](../phase0/README.md#可选-pi-rpc-探针)。
