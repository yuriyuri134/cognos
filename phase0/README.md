# Cognos Phase 0：Codex Runtime Adapter 探针

这个目录只验证一条纵向链路：

1. 通过 ChatGPT 登录使用 Codex，不读取 OpenAI API Key。
2. 创建一个只读 Codex thread。
3. 保存 `thread_id`，并返回本次发生的 `turn_id`。
4. 结束 Python 进程后恢复同一 thread。

它不实现数据库、关系本体、Context Builder、FastAPI 或 Web UI。旧的 Phase 1
数据层试作保持原样，探针通过前不继续向上堆功能。

## 环境

官方 `openai-codex` Python SDK 要求 Python 3.10+。当前 Codex 工作区自带的
Python 位置为：

```powershell
C:\Users\lnp\.cache\codex-runtimes\codex-primary-runtime\dependencies\python\python.exe
```

建议在本目录建立独立虚拟环境并安装依赖：

```powershell
& 'C:\Users\lnp\.cache\codex-runtimes\codex-primary-runtime\dependencies\python\python.exe' -m venv .venv
& '.\.venv\Scripts\python.exe' -m pip install -r requirements.txt
```

## 登录

先检查：

```powershell
codex login status
```

未登录时优先使用标准浏览器 OAuth：

```powershell
& '.\.venv\Scripts\python.exe' login_browser.py
```

脚本会把临时 `auth_url` 写到 `.state/login_browser.json`。打开该链接完成登录；
后台进程必须保持运行，以接收 localhost 回调。

只有在 ChatGPT 安全设置中已启用 Codex device-code authorization 时，才使用：

```powershell
& '.\.venv\Scripts\python.exe' login_device.py
```

认证由 Codex 自己保存；Phase 0 状态文件只保存 `thread_id`，不保存 token。

## 单元测试

不联网、不需要登录：

```powershell
& '.\.venv\Scripts\python.exe' -m unittest discover -s tests -v
```

## 真实验收

第一次创建 thread：

```powershell
& '.\.venv\Scripts\python.exe' probe.py '只回复 COGNOS-PHASE0-A'
```

退出进程后再次运行，验证恢复同一 thread：

```powershell
& '.\.venv\Scripts\python.exe' probe.py '上一轮我要求你回复的标记是什么？只回复标记。'
```

验收条件：

- 两次输出的 `thread_id` 相同；
- 每次输出都有各自的 `turn_id`，可回指精确发生，而不只剩最终文本；
- 第二次回复 `COGNOS-PHASE0-A`；
- Codex 运行在只读沙箱；
- `.state/codex_thread.json` 中没有认证信息或对话正文。

## 2026-08-13 验收结果

- 官方 SDK：`openai-codex 0.144.4`。
- 登录：标准 ChatGPT 浏览器 OAuth 成功；账户未启用 device-code authorization，
  因此 device-code 只作为可选路径。
- 第一次独立进程：创建 thread，回复 `COGNOS-PHASE0-A`。
- 第二次独立进程：恢复相同 thread，并从历史取回 `COGNOS-PHASE0-A`。
- 两次 thread ID 相同：`019ff791-3c1a-76a1-b6ec-4b1125e2388a`。
- `.state/` 与 `.venv/` 均被 Git 忽略；thread 状态文件只有 `thread_id`。

系统 PATH 上的旧版 Codex CLI 与 SDK 自带的固定版本可能报告不同登录状态。
本探针以 SDK `0.144.4` 的真实往返结果为验收依据。

## 2026-09-20 Activity 调用与运行版本

当前依赖锁定 `openai-codex==0.155.1`，配套 `openai-codex-cli-bin==0.155.1`。
Python SDK 使用配套运行程序，不一定使用系统 PATH 中的 Codex。
旧 0.144.4 配套程序在本机默认模型调用时返回需要更新 Codex 的错误；更新配套依赖后真实活动成功。

普通 `send` 保留原有会话；`send_activity` 每次新建 ephemeral 会话，不读取或覆盖普通聊天的 thread 标识。
调用前保存 SDK 入参。新会话不等于完整模型输入可见：额外运行指令、配置和工具读取仍超出记录边界。
创作试用证据见 `../work/codex-activity-scope-trial-2026-09-20-v2.json`。

## 可选 Pi RPC 探针

`pi_runtime.py` 把本机 Pi CLI 作为独立、仅对话的异构 runtime 探针；它是暂时的
工程接入，不代表 Cognos 最终本体。普通聊天以 Pi 返回的 session id/path 恢复，
仅 Pi 自己读写 session JSONL；不导入历史会话，也不接触其他参与者的 state。
普通调用在同一 adapter 内串行；同一个 state 文件应只交给一个服务进程使用。

固定参数包含 `--mode rpc --no-tools --no-extensions --no-skills --no-prompt-templates`。
扩展也禁用，不能通过普通 `/api/messages` 使用 Pi 改代码；实际执行继续走 handoff/DSH。
CLI 参数以 argv 传递，不调用 shell。`COGNOS_PI_COMMAND` 是单个可执行文件名或完整路径，
不是带参数的 shell 命令。Windows 标准 npm `pi.cmd` 会解析为 Node +
`@mariozechner/pi-coding-agent/dist/cli.js` 直接启动；非标准包装器需提供原生可执行文件。

Activity 使用 `--no-session`，既不读取普通绑定，也不创建普通 session 目录。
`on_request` 记录协议、命令、cwd、prompt id、正文 SHA-256 和字符数；正文省略，
不读取或复制 auth/token。认证仍由 Pi 自己管理。stderr 持续排空，但错误仅提示
是否有诊断，不复制可能包含凭据的 provider 错误文本。Cognos 本来已有的 Activity
Context Snapshot 仍保存编排输入；Pi 自己加载的系统上下文不声称已完整捕获。

`thread_id` 是 RPC `get_state` 返回的 Pi session id；`turn_<uuid>` 是 adapter 为本轮
生成的引用（profile: `turn_ref_kind=adapter_generated`），不是 Pi 原生 entry/turn id。
`requested_model` 仅记录显式 `--model` 参数；`model_used` 仅记录本轮 assistant message
报告的模型，否则为 `None`，不拿 provider 或显示名代替模型。

RPC 使用二进制 LF JSONL，不按 Unicode 分隔符断行。带 disposition 的新版 prompt
响应需继续等待 `agent_settled`；旧版无 disposition 的响应兼容 `agent_end`。
结束、失败、超时或取消都会关闭 stdin、等待退出，必要时 terminate/kill 并回收进程。
不自动重试 prompt。只有成功回复后保存绑定；首次失败可能留下 Pi 自己创建但尚未绑定的
孤立 session 文件，不自动导入。已有绑定损坏、文件缺失或返回身份变化时明确报错。

协议依据：[官方 RPC](https://raw.githubusercontent.com/badlogic/pi-mono/main/packages/coding-agent/docs/rpc.md)、
[RPC commands](https://raw.githubusercontent.com/badlogic/pi-mono/main/packages/coding-agent/docs/rpc-commands.md)。
安装版本以 `pi --help` 为准；不支持所需禁用参数的版本应升级，不能删掉安全参数绕过。
本轮未运行真实 Pi/网络/凭据验收；离线 subprocess doubles 不能证明本机 CLI/provider 可用。

从仓库根目录运行离线验收（不改依赖）：

```powershell
$env:PYTHONPATH='phase0;web'
& phase0/.venv/Scripts/python.exe -m pytest -q -p no:cacheprovider phase0/tests/test_pi_runtime.py
& phase0/.venv/Scripts/python.exe -m pytest -q -p no:cacheprovider phase0/tests web/tests/test_runtime_profile.py
& phase0/.venv/Scripts/python.exe -m pytest -q -p no:cacheprovider web/tests phase0/tests phase1/tests test_phase1.py
& phase0/.venv/Scripts/python.exe -m py_compile phase0/pi_runtime.py phase0/tests/test_pi_runtime.py web/server.py web/runtime_profile.py web/activity_service.py
git diff --check
```

真实连续性验收应使用新的隔离目录，不要对已有第一发生场发测试消息。例如从根目录
执行以下脚本两次；第二次只问上一轮标记，确认相同 thread、不同 turn，之后再检查
Activity 未改变普通 state。此步骤会实际调用本机 Pi 和它配置的模型：

```powershell
$env:PYTHONPATH='phase0'
$env:PI_PROBE_MESSAGE='记住 COGNOS-PI-A，只回复这个标记。'
@'
import asyncio, os
from pathlib import Path
from pi_runtime import PiRuntimeAdapter, PiStateStore
root = Path("web/.state/pi-acceptance").resolve()
adapter = PiRuntimeAdapter(cwd=Path.cwd(), state_store=PiStateStore(root / "pi_thread.json"))
reply = asyncio.run(adapter.send(os.environ["PI_PROBE_MESSAGE"]))
print(reply)
'@ | & phase0/.venv/Scripts/python.exe -
# 再设置 PI_PROBE_MESSAGE='上一轮标记是什么？'，重新运行上面的 Python 脚本。
```
