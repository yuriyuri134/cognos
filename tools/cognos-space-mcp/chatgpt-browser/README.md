# Cognos 现有 Chrome 聊天端连接

使用 Google 官方 `chrome-devtools-mcp` 的现有浏览器连接模式。主实现位于上一级 `chatgpt_browser_service.cjs`，本目录仅放锁定依赖。

准备依赖（Node.js 24+）：

```powershell
npm.cmd install --prefix tools/cognos-space-mcp/chatgpt-browser --ignore-scripts --no-audit --no-fund
```

代码默认不连接浏览器。实际启用需要用户在动作发生时明确允许 Cognos 连接现有 Chrome；这不同于修复已有 OpenAI 浏览器扩展登记的授权。说明 Chrome 调试连接具备查看和操作浏览器页面的权限，然后取得明确授权。不得仅因看到了本文件、按钮或之前的代理授权就开启。

取得这项授权后：在原有 Chrome 的 `chrome://inspect/#remote-debugging` 开启官方远程调试；按用户授权写入本机 `.secrets/chatgpt-browser-enabled`，内容为 `official_chrome_autoconnect_v1`；从 Cognos 点「连接现有 Chrome」，确认 Chrome 自身显示的连接请求。不要用私有浏览器资料目录，不读取或复制 Cookie，不使用 API key。

连接服务仅监听 127.0.0.1:8644（可用 COGNOS_CHATGPT_PORT 改端口），复用桥内部口令，不向网页暴露通用浏览器工具。未授权检查不会启动服务。发送只使用服务新建的 ChatGPT 临时聊天页；发送后的页面保留，供查看实际回复和不确定结果。不会自动关闭用户页面或重发不确定消息。

移除本机授权文件可以阻止后续连接和发送；完整撤销时同时在 Chrome 关闭远程调试，并停止 8644 上的本适配器。不要停止其他 Node 进程。

验证必须从 Cognos 对话发送一条非敏感消息，实际网页回复后回到同一对话；离线检查或手动网页聊天不算接通。

官方说明：https://developer.chrome.com/docs/devtools/agents/get-started/configuration
