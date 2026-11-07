# ChatGPT 聊天端接入状态

2026-11-07，Cognos 0.37.3。

## 已实际完成

- 恢复官方浏览器插件缓存、本机连接文件与 Chrome 登记；现有 Chrome 和 Edge 均可连接。
- 按用户明确授权启用并保留 Clash 系统代理，恢复两个浏览器对 ChatGPT 的访问。
- 在既有 dkwo Plus 登录中看到 111 项目；真实 Chat 模式选择 High 后收到「聊天端验证通过」。这是网页验证，并非 Cognos 自动收发验证。
- Cognos 对话区提供 Claude、GPT（聊天端）、Codex。聊天端独立保存默认、低、中、高；Codex 保留原有六档强度和独立偏好。
- 0.37.3 更新静态资源版本，解决普通刷新仍使用旧长缓存的问题。

## 已实现，等待实际授权验证

`tools/cognos-space-mcp/chatgpt_browser_service.cjs` 是独立于 Codex 会话的本机适配器，使用 Google 官方 Chrome DevTools MCP 的 `--autoConnect`，只连接原有 Chrome，不启动浏览器或创建新登录目录。依赖锁定在 `chatgpt-browser/package-lock.json`。

适配器只在本机回环地址监听，沿用现有桥内部口令；前端拿不到该口令。只有明确点连接才会启动适配器。Google 用量统计、性能查询及无关工具类别关闭。

消息进入 Cognos 后沿现有运行登记和结果回流路径处理：携带对话全文与入口、新建属于适配器的临时 ChatGPT 聊天页、核对可见账号和实际强度、发送一次、等完整可见回复，再回到原 Cognos 对话。不会回退到 Claude 或 Codex。用户原有标签页和草稿不参与自动操作。

运行回执在忽略目录 `deploy/nextcloud-trial/evidence/chatgpt-browser/`，按 run_id 和输入摘要绑定。发送前持久记一笔；发送后断线或超时保留网页，不自动重发。完整回复回执保存正文和来源链接。临时聊天仍使用账号现有的个性化设置，不能被当成隔离模型实验。

**尚未允许 Cognos 的新浏览器连接，也未启用 Chrome 远程调试。** 所有连接操作目前返回 `authorization_required`。授权记录只能在用户明确允许后写入本机 `.secrets/chatgpt-browser-enabled`；目前不存在。启用方式和影响见「GPT-聊天端持久连接方案.md」。

## 验证范围

- 7 项 Python 检查通过：ChatGPT 与 Codex 执行隔离、非法输入、运行编号及强度传递。
- Node 离线检查通过：仅连接现有 Chrome、授权门槛、页面归属、账号变化拒绝、完整回复回流、同一运行去重以及不确定发送不重试。未启动 Google 浏览器连接。
- 官方 Google 发布包 CLI 参数检查通过（仅 `--help`，不连接浏览器）。PHP / JS 语法及 `git diff --check` 通过。
- 8780 真实 API 验证通过：默认/低/中/高保存、旧扩展值拒绝、旧偏好回退默认、Codex high 偏好保留、其他账号不能读取验证对话的连接状态；未授权连接不接触浏览器。
- 通过官方已连接 Chrome 核验首页、Initium、Norm、项目页，桌面及 390px 共 8 个视图正常，无页面错误或横向溢出；另核验验证对话 589 的新选择区与手机布局。旧 `check_smoke.cjs` 自建无头浏览器，因此本次用同一组路由和断言在官方浏览器工具中执行，没有额外创建登录环境。
- 桥重载后唯一实例，Claude/Codex 仍可用，原有 commands 开关保留为 true。0.37.3 升级成功、maintenance=false、needsDbUpgrade=false；升级报告已有的 federated_invites 缺表及 space_traces judgment 列差异，本次未修改这些表。

**Cognos → 真实 ChatGPT → 同一 Cognos 对话的自动往返尚未运行，不能宣称接通。** 下一步仅是在明确授权后启用现有 Chrome 的官方连接、确认浏览器提示，并从验证对话发一条非敏感消息完成实际验收。

当前证据位于本机 `Documents/ChatGPT/111/chat-end-work/` 的 `chatgpt-web-verification.json`、`cognos-official-browser-smoke.json` 与 `cognos-chat-prepared*.png`。运行结果不入 Git。
