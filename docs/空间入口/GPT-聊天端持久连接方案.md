# 聊天端持久连接：实现前说明

## 要改什么

将 Cognos 的聊天端适配器改为本机持久连接服务，使用 Google 官方 Chrome DevTools MCP 的 `--autoConnect`，连接用户已有的 Chrome。通过正式 MCP 客户端操作普通 ChatGPT 网页，回复由 ChatGPT 产生，再沿现有 Cognos 运行登记、请求归属和结果回流流程交回。

## 当前设计为什么不够

独立 Chrome 用户目录会选错登录环境，已经移除。当前 Codex 会话中的浏览器控制可用于核实和排障，但依赖会话权限与生命周期，不能被当成 Cognos 长期后端。网页能正常聊天，也不等于 Cognos 自动收发已经完成。

## 影响范围

- Cognos 本机桥的 ChatGPT 适配器及独立 Node 依赖目录。
- ChatGPT 独立的思考强度：按当前真实网页已观察到的低、中、高和默认设置，不沿用先前未经验证的 standard/extended。
- 前端增加明确的「连接现有 Chrome」与连接状态，不启动新浏览器或创建其他用户目录。
- 连接服务仅监听本机回环地址，复用桥的内部口令保护状态和回复操作；不向网页公开口令。
- Google 工具仅使用自动连接模式，关闭用量统计、性能报告等非必要能力，不修改全局 Codex 配置。

## 权限和验收

代码和不访问浏览器的检查可以先准备。实际启用 Chrome 调试与允许 Cognos 连接属于新的浏览器访问授权，必须在代码完成后单独向用户说明，取得明确授权并通过 Chrome 自身确认流程。未经这一步，不启用调试、不尝试连接、不读取用户浏览器数据。

验收必须是从 Cognos 验证对话发出一条非敏感消息，实际 ChatGPT 网页以选择的强度回复，再在原 Cognos 对话中看到该回复。只通过网页手动验证、只通过模拟检查、只看到已连接标签都不算完成。

来源：https://developer.chrome.com/docs/devtools/agents/get-started/configuration
