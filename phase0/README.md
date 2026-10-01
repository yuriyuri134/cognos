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
