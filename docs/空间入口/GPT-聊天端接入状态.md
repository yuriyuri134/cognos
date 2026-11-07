# ChatGPT 聊天端接入状态

2026-11-07：对话选择区区分 Claude、GPT（聊天端）、Codex，各自保留思考强度偏好。ChatGPT 入口尚未完成真实收发接入。

此前尝试用独立 Chrome 用户目录打开 ChatGPT，导致 Cognos 检查的是新窗口的登录状态，而不是用户已有的登录窗口。这条路径已经移除：

- 连接检查不再启动浏览器、读取独立用户目录或要求重复登录。
- 页面移除「打开聊天端」按钮，只提供连接检查和真实未接通状态。
- 未接通的请求在进入执行队列之前返回错误，不转用 Claude 或 Codex，不伪造聊天端回复。
- 旧的 connect_chatgpt 请求仅返回连接状态，不打开其他窗口。

已验证：本地接口、选择区静态脚本和独立浏览器误启动回归检查。另已通过官方浏览器工具读取现有 Chrome 的 Cognos 页面，在 Edge 的已有 dkwo Plus 登录中找到 111 项目，选择真实网页的 High 思考强度，并收到「聊天端验证通过」。这些浏览器验证不等于 Cognos 已经自动接入。

当前状态：官方插件目录、Browser/Chrome 插件以及本机连接登记均已恢复。Chrome 与 Edge 此前访问 ChatGPT 均报 `ERR_CONNECTION_CLOSED`；通过现有 Clash 本机代理的公共首页连通测试成功后，按用户明确授权启用了现有系统代理，网页访问已恢复。Cognos 发起的自动收发仍未完成，需要实现独立、持久且经过浏览器授权的连接，不能依赖当前 Codex 助手会话冒充正式后端。

检查：

```
node tools/cognos-space-mcp/test_chatgpt_browser.cjs
node tools/cognos-space-mcp/test_chatgpt_stream.cjs
```

桥的请求边界与回复者隔离见 `test_chatgpt_entry.py`。这些工程检查不代表真实 ChatGPT 接入已经完成。
