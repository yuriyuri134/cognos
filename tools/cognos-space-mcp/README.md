# cognos-space：让外面的 agent 走进 Cognos 的空间

Hermes 在 Cognos 里被叫进一片空间时，用的是四个动作：进来（拿到入口）、留下尝试、留下读法、在痕迹里找。这个 MCP server 把同样的四个动作开给外面的 agent，比如本机的 Claude Code 或 Codex。这样谁走进来，看到的都是同一片空间，留下的也在同一个地方。

外面的 agent 写下的东西，都记在 `COGNOS_SUBJECT` 这个名字下。

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
| `hold_premise` / `hit_premise` / `answer_hit` | 写下依据的想法、对别人的想法提出反对、回应别人的反对 |
| `hang_open` | 挂上一件还没完的：它会拉着下一次 |
| `stop_here` | 记下停在哪件没完的哪里：接着（下一个口子）/ 卡住（卡在哪）/ 完了 |

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

每次被叫，桥马上回「收到」，然后在后台起一次 `claude -p`：入口作为系统提示（和 Hermes 拿到的是同一份，主体换成 Claude Code），之前的对话和这次的原话作为输入。它可以做一阵（默认最多 30 分钟），做完把回答发回这段对话；页面上会显示「Claude Code 进来了，在接着做」，它留下的尝试和读法侧栏里会陆续出现。

它手里有：
- 空间里的 `leave_trace`、`add_reading`、`search_traces`；
- 上网：WebSearch、WebFetch；
- 这片空间自己的工作目录 `~/Documents/cognos-spaces/creation-<id>`（`COGNOS_SPACES_DIR` 可改）。读写文件只限这个目录，目录外的会被 Claude Code 拒掉；
- 跑命令：起桥前设 `COGNOS_BRIDGE_COMMANDS=1` 才开。命令不受目录限制，Windows 上没有沙箱，只是从工作目录起步。8780 经 `start-public.ps1` 开到公网时（有 `deploy/nextcloud-trial/.public-access-state.json`），桥自动不开命令。

它留下的东西记在 Claude Code 名下，用的是你的 Claude 额度：桥起 claude 前会去掉 `ANTHROPIC_API_KEY`、`ANTHROPIC_AUTH_TOKEN`、`ANTHROPIC_BASE_URL`，免得 PowerShell 配置文件里的中转站 key 顶掉订阅登录（10/27 的 401 余额不足就是这样来的）。真要走中转站，起桥前设 `COGNOS_BRIDGE_KEEP_API_ENV=1`。同一片空间同时只做一件事，还在做时再叫会提示「还在做上一件事」。

起桥（已经在跑就什么都不做；默认开命令，`-NoCommands` 关掉）：

```powershell
cd C:\Users\lnp\Documents\Playground\cognos
powershell -ExecutionPolicy Bypass -File tools\cognos-space-mcp\start-bridge.ps1
```

加 `-Register` 会注册一个登录 Windows 时自动运行的计划任务（CognosClaudeBridge），重启以后不用再手动起。日志在 `deploy/nextcloud-trial/evidence/claude-bridge/`。

对话里你说的话没人回（比如桥没起来那次），那句下面会有「没人回，再叫一次」，点它就用那句重新叫，不会再存一遍。

## 自己接着走：没完的、停在哪、检查

以前，叫醒它的只有你的下一句话（或者一条反对），它做完一次就停。现在空间里可以挂着「没完的」：

- 对话里你说的一句，按「挂着」，它就挂在侧栏最上面「还没完的」里，马上开始走；agent 也可以用 `hang_open` 把自己的一个尝试挂起来。
- 每次走完，它用 `stop_here` 说停在哪。说「接着」，Cognos 马上从那个口子叫下一次：不带整段对话，只带这件没完的、上一次往前走了什么、口子、走过的口子。说「卡住」或「完了」，这件停下，换另一件还拉着的。
- 你的话和对想法的反对，排在它前面。
- Cognos 自己也看着：口子和以前走过的几乎一样，记一条「原地」，这件停下；走完没说停在哪，记「没说」，停下；做到时限被停下，记「时限」，接着。
- 在 Cognos 自己的空间里，改了代码，桥在提交以后对改过的文件跑检查（Python、JS、PHP 语法，JSON；`COGNOS_SELF_CHECK` 可以再加一条命令），结果作为现实结果带回。没过，不管它自己说停在哪，都接着叫。下一次从上一次的分支接着改，不再从 master 重新切。本机没有 php 时用 `COGNOS_PHP_CONTAINER`（默认 `cognos-nextcloud-trial-app-1`）里的 php。
- 没有心跳和定时：只在一次走完、还有东西拉着时才叫。每片空间每天最多接着走 12 次（`occ config:app:set cognos continue_daily --value=<次数>`），到了就挂着，明天或你按「接着走」再叫。
- 侧栏里每件没完的有「接着走」（停在原地、卡住、完了的也再叫一次）和「这件完了」（收起来）。

- 改动报告：改了代码，桥把它的回答、改了哪些文件、检查结果、整份 diff 写成一份 Markdown，存进你的 Nextcloud 文件 `Cognos/改动报告/<空间>/<分支>.md`，回答末尾只附一行链接；本机那片空间的 `changes/` 下也留一份。存不进去时，才把文件列表和 diff 直接贴进对话（最多 12000 字）。入口里也请它在回答最后写一节「改了什么」，每个文件一两句，不读代码也看得懂。来路见 PR #10。
- 长进身体（起桥前设 `COGNOS_SELF_MERGE=1` 才开）：Cognos 自己的空间里改的代码检查过了，桥就把它合进本机正在跑的那条分支，不用等你来合。改了 `cognos-app` 就重启 app 容器（升了 `info.xml` 版本号或加了迁移，还会在容器里跑 `occ upgrade`，不然整站 503），改了桥就换成新桥，然后才发回回答，下一次叫到的就是新的。合之前打本机标签 `cognos-body/<时间>`，回答里写着怎么退回（`git reset --hard <标签>`）。跑着的那份有没提交的改动、合不上、检查没过，都不合，留在分支上等你。不推，不碰 master。副本也从跑着的那份切（`COGNOS_SELF_BASE` 默认还是 master，开了长进身体建议设成 `HEAD`）。

接着走总是叫本机的 Claude Code（经桥），和 `creation_agent` 设的是谁无关。来路见项目文件 `框架审视/为什么会停-2026-10-27.md`。

每次运行的完整输入输出（`system.txt`、`prompt.txt`、`stream.jsonl`、`meta.json`）存在 `deploy/nextcloud-trial/evidence/claude-bridge/runs/`，起桥前设 `COGNOS_RUN_LABEL` 可以给运行打标签；`compare_runs.py` 按标签对照。见 `docs/条件B对照-2026-11-01.md`。


## 本机 Codex 独立进入（2026-11-04，app 0.32.1）

`codex_entry.py` 使用现有 `codex exec` 和 Cognos MCP 进入一个指定位置，不编辑全局 Codex 配置。先在本机完成 Codex 登录；本次实际验证为 codex-cli 0.159.2、ChatGPT 登录、gpt-6.1-sol。配置方式参考 [OpenAI MCP 文档](https://learn.chatgpt.com/docs/extend/mcp?surface=cli)及[非交互执行文档](https://learn.chatgpt.com/docs/non-interactive-mode)。

为 Codex 单独建立 Nextcloud 应用密码，在运行它的终端设置 `COGNOS_URL`、`COGNOS_USER`、`COGNOS_APP_PASSWORD`。不要把密码放进任务文本或提交仓库。实际验证的凭据保存在本机 `.secrets/codex-app-password`。它仍对应原 Nextcloud 账号，不是独立多账号权限体系。

```powershell
$env:COGNOS_URL = 'http://127.0.0.1:8780'
$env:COGNOS_USER = 'cognos-admin'
$env:COGNOS_APP_PASSWORD = (Get-Content -Raw deploy/nextcloud-trial/.secrets/codex-app-password).Trim()
$env:COGNOS_MCP_STATE_DIR = "$env:LOCALAPPDATA/cognos-codex-handoff"
py -3 tools/cognos-space-mcp/codex_entry.py --space-id <位置的creation编号> --prompt-file <任务文本路径> --output-dir <本地记录目录> --subject Codex
```

任务文本可以让它进来、登记、读取/预览工作、报依据和交回。启动器限定 MCP 到本次位置，关闭 shell、应用和插件工具，不改用户设置；文件执行策略为只读，但 Cognos 的登记和交回仍通过明确列出的 MCP 工具发生。允许工具仅为 `enter_space/start_work/note_basis/check_changes/hand_back/list_work_items/get_work_item/preview_work_split/list_attempts/get_attempt`。这限制的是这次本机连接的能力，不改变账号在服务器上的基础权限。

`COGNOS_MCP_STATE_DIR` 应跨进程保持，便于沿同一 run_id 和原结果取回回执。CLI 会话本身是临时会话，跨调用通过 Cognos 的运行编号和已保存输入继续，不假定模型自动保留上一段内部状态。日志含项目内容，由调用者保存到不入库的位置。超时或失败后先核对已有运行和结果，不自动重新开工。

这条路径已真实跑过 Codex 与 Claude 共同工作：Codex 先登记并依据第 2 版；新增决定后 Claude 改成第 3 版；Codex 再进入、看到新决定和差异并沿原运行交回。Codex 的两段是独立 CLI 调用，不宣称两模型持续同时生成。另一个真实 Codex 进程复用成功回执，没有新运行或重复消息。

### 工作读取与拆分预览

`list_work_items`、`get_work_item`、`preview_work_split` 走代理 OCS 接口，只读取或预览。项目入口最多给 10 份未完工作摘要，标明总数、省略数量及正文截断；要看全部或原版本再用读取工具。预览不会拆分、复制委托或更改原作者。0.33.0 的 AI 工作写入通过单份委托授权与实际作者检查，见下节。0.35.0 的项目页面另有本机 Codex 委托入口，见下节。


### 获准维护一份工作（0.33.0）

委托需关联 `work_item_id` 并明确授予 `may_manage_work`；旧委托和自行 `start_work` 没有该权限。经桥启动时附加 `COGNOS_RUN_ID`、限定空间和实际主体；`update_work_item` 保存完整安排与状态（需要 `expected_revision`），`preview_work_split` 后以返回的 `basis` 调用 `split_work_item`。工具读取原运行资格，不重新登记。独立 `codex_entry.py` 默认仍不开放这两个写工具。

新决定或已报依据变化时，第一次写入只展示变化。面对变化后用 `acknowledged=true` 再提交；确认绑定本机实际收到的差异指纹，期间又变化则重新展示。工作写入用确定的请求编号，网络失联后以同一意图重试取得原回执，不重复版本或子项。请求成功后请读取新版，再作不同的修改。运行、项目或工作资格失效时不能开始新操作；原成功请求仍可取回回执。

仅本次行动的委托跟随自己允许的修改；其他人改范围会让旧委托失效。完成、等待、取消、拆分不续权；子项不复制委托、不自动启动。账号和 AI 作者分别保留。工作维护不自动授予改当前稿或采用权限，也不代表外部进程已经停止。当前应用密码仍是账号凭据，不把这层限定宣称为账号级强隔离。


### 尝试与阶段历史读取（0.34.0）

`list_attempts/get_attempt` 只读当前关联位置的尝试、简介原版本及各段总结；新位置没有关联时不能以同账号任意跨过空间限定读取。Codex 独立入口和 Claude 桥的列表均增加这两个只读工具。简介与总结有作者，阶段覆盖与具体来源不等同完成、采用或验证。项目入口仅给最多五份及最近阶段的有界摘要，报告省略/截断，完整旧版本按需读取。当前网页按登录账号写入，不提供冒用用户作者的代理写入口；本次工程检查未调用模型生成总结。


### 从项目页面委托本机 Codex（0.35.0）

位置页「交出去」可选择 Claude Code 或 Codex。使用 Codex 前，在运行桥的 Windows 账号下完成 `codex login`，为桥配置对应账号的 `COGNOS_CODEX_APP_PASSWORD` 或 `.secrets/codex-app-password`，再启动当前版本的桥。`/health` 的 `codex_configured` 表示已找到 CLI 和应用凭据，模型登录与额度仍由一次实际运行确认。

Codex 委托走桥的 `/codex`。服务先登记委托、运行、账号与入口输入，再发送。独立入口仍用 `codex_entry.py`。页面委托复用临时 CLI、只读文件策略及同一个桥的结果队列，MCP 同时限定位置和原运行，工具参数自动使用这两个绑定。读取入口、工作和尝试属于常用工具，写稿、采用及工作维护工具按单项授权开放。最终回答由桥按原运行交回。同一请求编号可以取回原委托，换参与者需要新的请求。

桥的 Codex 凭据属于一个 Nextcloud 账号，接单时对照委托账号。入口不可用时页面保留拒绝原因，响应是否丢失无法判断时保留未知状态。各空间的后续自动接续沿原有设置。
