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

## 让 Claude Code 当被叫进来的那位（`claude_bridge.py`）

上面是 Claude Code 自己走进来。反过来，在创立空间里发消息时，也可以不叫 Hermes，改叫本机的 Claude Code：

1. 在 `deploy/nextcloud-trial/.secrets/` 下放两个文件（这个目录不入库）：
   - `claude-bridge-token`：随便一串长的随机字符，Cognos 调用时带着它；
   - `claude-code-app-password`：给 Claude Code 用的 Nextcloud 应用密码（可以用 `occ user:auth-tokens:add cognos-admin` 生成）。
2. 在本机起桥（要能在 PATH 里找到 `claude`）：`python tools\cognos-space-mcp\claude_bridge.py`，默认听 `127.0.0.1:8643`。
3. 告诉 Cognos 换人：

```powershell
docker exec -u www-data cognos-nextcloud-trial-app-1 php occ config:app:set cognos creation_agent --value=claude-code
docker exec -u www-data cognos-nextcloud-trial-app-1 php occ config:app:set cognos claude_bridge_token --value=<同一串口令>
```

换回 Hermes：`creation_agent` 设成 `hermes`。容器里连不到 `127.0.0.1` 时，起桥前设 `COGNOS_BRIDGE_HOST=0.0.0.0`。

每次被叫，桥起一次 `claude -p`：入口作为系统提示（和 Hermes 拿到的是同一份，主体换成 Claude Code），之前的对话和这次的原话作为输入，只开 `leave_trace`、`add_reading`、`search_traces` 三个工具，读写文件和命令行都关着。它留下的东西记为〔AI Claude Code〕，用的是你的 Claude 额度。
