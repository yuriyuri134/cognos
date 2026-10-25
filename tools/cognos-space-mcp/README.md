# cognos-space：让外面的 agent 走进 Cognos 的空间

Hermes 在 Cognos 里被叫进一片空间时，用的是四个动作：进来（拿到入口）、留下尝试、留下读法、在痕迹里找。这个 MCP server 把同样的四个动作开给外面的 agent，比如本机的 Claude Code 或 Codex。这样谁走进来，看到的都是同一片空间，留下的也在同一个地方。

外面的 agent 写下的东西，一律记为〔AI〕加 `COGNOS_SUBJECT` 这个名字。它不能代替交互对象写原话。

只用 Python 标准库，不用装依赖。

## 配置

1. 在 Nextcloud（http://127.0.0.1:8780）的「设置 → 安全 → 设备与会话」里新建一个应用密码。
2. 在 Claude Code 里加上这个 server（Windows，路径按自己的仓库位置改）：

```powershell
claude mcp add cognos-space `
  -e COGNOS_URL=http://127.0.0.1:8780 `
  -e COGNOS_USER=cognos-admin `
  -e COGNOS_APP_PASSWORD=<应用密码> `
  -e COGNOS_SUBJECT="Claude Code" `
  -- python C:\Users\lnp\Documents\Playground\cognos\tools\cognos-space-mcp\server.py
```

Codex 等别的 agent 也一样，各自报自己的 `COGNOS_SUBJECT`。

## 工具

| 工具 | 做什么 |
|---|---|
| `list_spaces` | 列出能走进去的空间 |
| `enter_space` | 拿到入口：你在哪、Cognos 为了什么、意图（原话）、以前的尝试和停在哪、事实、读法、空白 |
| `leave_trace` | 留下一条发生：尝试（停在哪、碰没碰现实）、推出来的事实、更正 |
| `add_reading` | 留下读法，旧的不删 |
| `search_traces` | 在全部痕迹里找 |

后台走的是 OCS 接口 `/ocs/v2.php/apps/cognos/api/v1.0/spaces/...`，见 `deploy/nextcloud-trial/cognos-app/lib/Controller/SpaceApiController.php`。
