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
| `hold_premise` / `hit_premise` / `answer_hit` | 押上前提、顶别人押着的、面对顶到自己的 |
| `hang_open` | 挂上一件还没完的：它会拉着下一次 |
| `stop_here` | 走之前说清停在哪件没完的哪里：接着（下一个口子）/ 卡住（卡在哪）/ 完了 |

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

每次被叫，桥马上回「收到」，然后在后台起一次 `claude -p`：入口作为系统提示（和 Hermes 拿到的是同一份，主体换成 Claude Code），之前的对话和这次的原话作为输入。它回完话不用马上走，能自己做的先做到够不着为止（默认最多 30 分钟），做完把回答发回这段对话；页面上会显示「Claude Code 进来了，在接着做」，它留下的尝试和读法侧栏里会陆续出现。

它手里有：
- 空间里的 `leave_trace`、`add_reading`、`search_traces`；
- 上网：WebSearch、WebFetch；
- 这片空间自己的工作目录 `~/Documents/cognos-spaces/creation-<id>`（`COGNOS_SPACES_DIR` 可改）。读写文件只限这个目录，目录外的会被 Claude Code 拒掉；
- 跑命令：起桥前设 `COGNOS_BRIDGE_COMMANDS=1` 才开。命令不受目录限制，Windows 上没有沙箱，只是从工作目录起步。8780 经 `start-public.ps1` 开到公网时（有 `deploy/nextcloud-trial/.public-access-state.json`），桥自动不开命令。

它留下的东西记为〔AI Claude Code〕，用的是你的 Claude 额度：桥起 claude 前会去掉 `ANTHROPIC_API_KEY`、`ANTHROPIC_AUTH_TOKEN`、`ANTHROPIC_BASE_URL`，免得 PowerShell 配置文件里的中转站 key 顶掉订阅登录（10/27 的 401 余额不足就是这样来的）。真要走中转站，起桥前设 `COGNOS_BRIDGE_KEEP_API_ENV=1`。同一片空间同时只做一件事，还在做时再叫会提示「还在做上一件事」。

起桥（已经在跑就什么都不做；默认开命令，`-NoCommands` 关掉）：

```powershell
cd C:\Users\lnp\Documents\Playground\cognos
powershell -ExecutionPolicy Bypass -File tools\cognos-space-mcp\start-bridge.ps1
```

加 `-Register` 会注册一个登录 Windows 时自动运行的计划任务（CognosClaudeBridge），重启以后不用再手动起。日志在 `deploy/nextcloud-trial/evidence/claude-bridge/`。

对话里你说的话没人回（比如桥没起来那次），那句下面会有「没人回，再叫一次」，点它就用那句重新叫，不会再存一遍。

## 自己接着走：没完的、停在哪、检查

以前，叫醒它的只有你的下一句话（或者一下顶），它做完一次就停。现在空间里可以挂着「没完的」：

- 对话里你说的一句，按「挂着」，它就挂在侧栏最上面「还没完的」里，马上开始走；agent 也可以用 `hang_open` 把自己的一个尝试挂起来。
- 每次走完，它用 `stop_here` 说停在哪。说「接着」，Cognos 马上从那个口子叫下一次：不带整段对话，只带这件没完的、上一次往前走了什么、口子、走过的口子。说「卡住」或「完了」，这件停下，换另一件还拉着的。
- 你的话和押着的前提被顶，排在它前面。
- Cognos 自己也看着：口子和以前走过的几乎一样，记一条〔世〕「原地」，这件停下；走完没说停在哪，记「没说」，停下；做到时限被停下，记「时限」，接着。
- 在 Cognos 自己的空间里，改了代码，桥在提交以后对改过的文件跑检查（Python、JS、PHP 语法，JSON；`COGNOS_SELF_CHECK` 可以再加一条命令），结果作为〔世〕带回。没过，不管它自己说停在哪，都接着叫。下一次从上一次的分支接着改，不再从 master 重新切。本机没有 php 时用 `COGNOS_PHP_CONTAINER`（默认 `cognos-nextcloud-trial-app-1`）里的 php。
- 没有心跳和定时：只在一次走完、还有东西拉着时才叫。每片空间每天最多接着走 12 次（`occ config:app:set cognos continue_daily --value=<次数>`），到了就挂着，明天或你按「接着走」再叫。
- 侧栏里每件没完的有「接着走」（停在原地、卡住、完了的也再叫一次）和「这件完了」（收起来）。

- 长进身体（起桥前设 `COGNOS_SELF_MERGE=1` 才开）：Cognos 自己的空间里改的代码检查过了，桥就把它合进本机正在跑的那条分支，不用等你来合。改了 `cognos-app` 就重启 app 容器（升了 `info.xml` 版本号或加了迁移，还会在容器里跑 `occ upgrade`，不然整站 503），改了桥就换成新桥，然后才发回回答，下一次叫到的就是新的。合之前打本机标签 `cognos-body/<时间>`，回答里写着怎么退回（`git reset --hard <标签>`）。跑着的那份有没提交的改动、合不上、检查没过，都不合，留在分支上等你。不推，不碰 master。副本也从跑着的那份切（`COGNOS_SELF_BASE` 默认还是 master，开了长进身体建议设成 `HEAD`）。

接着走总是叫本机的 Claude Code（经桥），和 `creation_agent` 设的是谁无关。来路见项目文件 `框架审视/为什么会停-2026-10-27.md`。

### 打转和选哪一件：不靠字面像不像、不按挂上的先后

- 口子和以前走过的像（字二元组 ≥0.6），只触发复核，不直接判「原地」：看这之间有没有外面来的东西（交互对象的话、没过的检查、别的主体写的、别人对的判断）。有，不算原地；没有，下一次 `stop_here` 接着要写 `new_ground`（相比以前新增了什么依据或前提变化），不写会被拒（422），说不出就该停。写了放一次，放过一次口子还是像、外面还是没有新东西，才记〔世〕「原地」。
- 同一片空间有几件没完的拉着时，下一次做哪件由它选：`stop_here` 的 `next_open` 写 #id，`judgment` 写为什么现在做它比别的更值得、预期会碰回什么（以后可以用 `check_judgment` 对；自己对自己不进账）。不选，才退回刚走完那件、再按挂上的先后。
