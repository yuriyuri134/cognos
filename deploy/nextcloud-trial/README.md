# Nextcloud 隔离底座试用

这是本机选型环境，未迁移旧 Cognos。它不等于完整 Hub：含 Nextcloud/Postgres/Redis/cron，bootstrap 安装 Deck、Talk、Collectives 与 Team folders；协作 Office 需要另配并验收。

PowerShell 中以绝对路径执行，避免旧会话中的工作目录问题：

可复制的一行入口（Windows PowerShell 也可用）：

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File C:\Users\lnp\Documents\Playground\cognos\deploy\nextcloud-trial\run-trial.ps1
```

分步排查时：

```powershell
& 'C:\Users\lnp\Documents\Playground\cognos\deploy\nextcloud-trial\start.ps1' -PrepareOnly
& 'C:\Users\lnp\Documents\Playground\cognos\deploy\nextcloud-trial\start.ps1'
& 'C:\Users\lnp\Documents\Playground\cognos\deploy\nextcloud-trial\bootstrap.ps1'
```

Windows 需要 Docker Desktop Linux 容器引擎；如默认端点未指向运行的引擎，可明确传 `-DockerHost 'npipe:////./pipe/dockerDesktopLinuxEngine'`。Compose 校验不需要引擎；运行需要引擎及镜像/App Store 网络。

若镜像拉取出现 `reading HTTP CONNECT: unexpected EOF`，故障发生在代理隧道。本机 2026-10-07 经 HTTP 客户端核查，Clash 的 `http://127.0.0.1:7897` 可访问 Registry（无认证请求返回预期 401）。在 Docker Desktop Settings → Resources → Proxies 中，手动 HTTP/HTTPS 地址均填此地址；若有 Containers proxy，选 Same as host proxy，Apply & Restart 后重跑入口。端口是本机核查结果，其他机器应按其实际代理设置。参考 [Docker 官方代理设置](https://docs.docker.com/desktop/settings-and-maintenance/settings/#proxies)。这不是 Docker 引擎实际拉取成功的证据，也不需要删除已有卷。

访问 http://127.0.0.1:8780 。管理员用户名 `cognos-admin`，生成的密码只保存 `.secrets/admin-password`；不要把密钥或 `config.php` 放进验收报告。重复运行不覆盖密码、不销毁卷。默认没有公网、SMTP 邀请、HTTPS、Office、真实 AI 或旧资料迁移；它们各自需要后续验收，不能由容器启动推断完成。

## 异地小范围试用（临时 HTTPS）

这条路径用于让少量认识的同学从不同地点实际试用，不是长期部署。它使用 Cloudflare Quick Tunnel，停止窗口后 URL 会失效；不要在没有备份和权限复核时开放注册或存放敏感材料。Quick Tunnel 的 `cloudflared` 镜像已固定 digest，但临时域名本身不固定。

先确认 Cognos 的 `web/.env` 和运行时凭据已准备好，然后在一个 PowerShell 窗口启动：

```powershell
cd C:\Users\lnp\Documents\Playground\cognos\deploy\nextcloud-trial
.\start-public.ps1
```

脚本会启动本机 Cognos、只转发 Talk webhook 的代理，以及两个 HTTPS 隧道。输出的 `Nextcloud (members)` URL 给同学访问；输出的 `Talk webhook` URL 交给 bot 安装脚本。脚本不会把 Cognos 首页、bootstrap、raw 历史或登录 API 放进公网隧道。

把输出的 webhook URL 安装成 Talk bot：

```powershell
.\install-talk-bot.ps1 -WebhookUrl 'https://<临时域名>/api/integrations/nextcloud-talk/webhook'
```

`install-talk-bot.ps1` 只把 bot secret 保存到 `.secrets/talk-bot-secret`；Cognos 进程由 `start-public.ps1` 读取同一个 secret。Nextcloud 35 没有 `talk:room:list` 命令，房间 token 从 Talk 界面或创建房间命令的输出取得，再执行：

```powershell
& docker --config .docker-client --host npipe:////./pipe/dockerDesktopLinuxEngine compose --project-directory . -f compose.yaml exec -T --user www-data app php occ talk:bot:setup <bot-id> <room-token>
```

Talk 中使用 `@Cognos @Gemini 你的问题`。默认要求提及 bot；未提及的普通消息不会调用模型。Talk 重试由签名、事件 ID 和独立 SQLite 投影去重；回复投递失败会重试已生成的回复，不重新调用模型。Talk 成员身份会以外部 `external_author_id` 留在现有 append-only raw 中，不会伪装成本地 Cognos 登录账号。

停止时不要只关闭启动窗口；可执行：

```powershell
.\stop-public.ps1
```

它会删除固定名称的临时隧道容器、结束本次 Cognos/webhook proxy 进程，并移除 Quick Tunnel 的 Nextcloud URL 覆盖配置。`install-talk-bot.ps1 -ReplaceExisting` 可在每次 URL 变化后更新 bot webhook；房间中的 bot 关系需要再次 `talk:bot:setup`。

这条试用路径仍未提供固定域名、长期 HTTPS 证书、邀请审批、注册限流、细粒度组织权限、备份恢复或公网安全审计。实际让同学使用前先用两个测试账号走一遍：加入 Talk、发送 `@Cognos`、断开/恢复网络、刷新后确认回复和 Cognos raw 引用。

镜像 Nextcloud 固定在 35.0.1；Nextcloud/Postgres/Redis 的 Docker Registry manifest 在 2026-10-07 核查为 HTTP 200，并已固定 digest。App Store 安装的实际应用版本也须记入证据。升级前备份，保留旧版本兼容记录。

停止时只对本 compose project 执行 `docker compose --project-directory <本目录> down`；不要加 `-v`，不要运行全局 prune。原始 Vault 和 web/.state 不挂载到容器。

第一轮验证两账号任务指派、成员共享文件、讨论、重启恢复、非成员拒绝、手机界面；结果逐条回填根目录 ACCEPTANCE.md。开源产品的功能介绍不能代替这条验收。

引擎与 Deck 就绪后，用现有 Python 环境运行真实任务 API 验收：

```powershell
& 'C:\Users\lnp\Documents\Playground\cognos\phase0\.venv\Scripts\python.exe' 'C:\Users\lnp\Documents\Playground\cognos\deploy\nextcloud-trial\check_tasks.py'
# 重启该 compose project 后：
& 'C:\Users\lnp\Documents\Playground\cognos\phase0\.venv\Scripts\python.exe' 'C:\Users\lnp\Documents\Playground\cognos\deploy\nextcloud-trial\check_tasks.py' --verify
```

脚本只访问本机 8780，创建三个唯一名称的试用账号和一个共享看板；报告保存于 evidence，账号凭据仅在 .secrets/task-journey.json。不输出凭据，不清理任何现有账号或数据。已有试用记录时拒绝再次创建，避免覆盖凭据；重复读取使用 --verify。验证覆盖任务指派、另一成员发现/修改、待处理→进行中→已完成三列流转及指派保留、重新读取、非成员访问拒绝，以及成员不能指派非成员且拒绝不改变负责人。列流转不等于 Deck 原生 done 标记或个人任务列表。时间线、浏览器、个人任务列表、邀请仍待验收，不可将 API passed 标作完整 J07 完成。脚本当前仅通过静态检查，尚未连接运行中的 Deck。接口依据 [Deck 官方 API](https://github.com/nextcloud/deck/blob/main/docs/API.md)，部署后仍须核对实际版本与响应。

2026-10-07 实际服务已通过上述 API 检查。随后浏览器脚本也真实通过 A 创建/指派、B 在 upcoming 找到并滚动到可见位置、390px 原生完成、完成后待办移除和重新进入历史六项检查。以根目录 ACCEPTANCE 的最新证据为准，不能沿用前段的历史未运行状态。

已有 API 试用账号和看板后，运行真实浏览器验收（需要本机 Chrome 与 Node）：

```powershell
& 'C:\Program Files\nodejs\node.exe' 'C:\Users\lnp\Documents\Playground\cognos\deploy\nextcloud-trial\check_tasks_browser.cjs'
```

每次创建唯一命名的试用卡片并保留，报告和截图保存 evidence；不清理其他数据。browser-shell.cjs 使用隔离临时 Chrome profile，不读取日常浏览器配置；只操纵本机试用服务。Chrome 渲染器采用项目既有 headless 参数，因此只用于这类受控本机验收。脚本的 passed 仍不是整条 J07 完成：权限隔离、同名身份、loading/error/retry、断网草稿保留、创建与历史原子性、服务重启等需要另取证据。

## Cognos 任务提交反馈扩展

真实断网验收发现 Deck 1.19.0 保留草稿、拒绝写入且可恢复重试，但没有可见的失败提示。cognos-app 是本地 Nextcloud 应用：借助公开的模板事件加载脚本，仅适配 Deck 1.19 实测的同源任务创建 XHR。请求期间显示状态并禁用提交，失败显示明确提示，成功移除提示。它不保存账号、密码或第二套任务数据，不改下载的 Deck 源码。此适配依赖当前端点和表单结构，升级 Deck 后须重跑验收；数据仍由 Deck 管理。

2026-10-07 已实际正式安装：PHP lint 通过、cognos 0.1.0 enabled，不注入源码的断网六项通过；四服务 restart 后旧卡片历史和扩展自动加载也通过。Compose 将源码只读挂载到 app 与 cron 的 custom_apps/cognos；统一入口重建这两个服务并保留原卷，bootstrap 先逐文件 php -l 再 app:enable cognos。今后的重部署仍需检查实际 app:list 和网页脚本加载，不能以浏览器注入替代安装成功证据。

安装后真实断网恢复验收：

```powershell
& 'C:\Program Files\nodejs\node.exe' 'C:\Users\lnp\Documents\Playground\cognos\deploy\nextcloud-trial\check_tasks_recovery.cjs'
```

--inject-feedback 仅用于安装前源码与真实 Deck 页面的集成诊断，报告必须注明注入，不能计为安装证据。断网后的请求结果可能不明确，因此提示先检查任务列表再重试；当前尚未实现跨服务的幂等请求，不声称任意响应丢失都不会重复写入。公开接口参考 [Nextcloud 启动](https://docs.nextcloud.com/server/stable/developer_manual/app_development/bootstrap.html) 与 [脚本加载](https://docs.nextcloud.com/server/stable/developer_manual/basics/front-end/js.html)。

补充验收入口：check_tasks_pending.cjs 验证真实延迟中的反馈及重复鼠标点击；check_tasks_restored.cjs 读取先前浏览器报告中的旧卡片历史（须先实际重启，脚本不自行重启）；check_task_permissions.py 使用新测试板验证匿名/同显示名/编辑权限与成员撤销，不改主试用看板成员。它会暂时将试用 outsider 显示名改成 B 的显示名，finally 恢复；仅覆盖 Deck 权限。各脚本通过不代表其他应用或完整 J07 已通过。


## Cognos 事务创建入口

当前本机 Deck 页面创建使用 `/ocs/v2.php/apps/cognos/api/v1.0/cards`，事务内复用 Deck 的创建/关联服务，检查创建历史后提交；失败回滚。支持当前本机普通看板，不承诺联邦远端事务。管理员关闭 Deck/活动或禁止作者活动日志时，入口明确失败，不能留下无创建历史的任务。

`check_task_creation_atomicity.py` 默认验收 Cognos 创建与关联回滚；`--native` 明确复现原生 Deck 的部分写入，保留试用对象，不把它当修复通过。`check_task_creation_history_fault.py` 需要访问正在运行的独立 Docker 引擎，仅为随机测试标题安装临时活动故障钩子，finally 清理。两脚本只使用已有隔离账号，报告在 evidence，无密码。

范围：创建的数据库任务/关联/活动，不覆盖所有 Deck 更新、外部通知、永久历史保留或未知提交结果幂等。独立试用不迁移旧 Cognos 数据。


## 创建重试与结果恢复（Cognos 0.2.0）

Cognos OCS 创建要求 requestId（16–64 个字母/数字/下划线/连字符），服务端按账号隔离。同 ID/相同内容重放原任务、标记 X-Cognos-Replayed；同 ID/不同已接受内容返回 409。浏览器自动生成并为未确认操作保留恢复键；同页签刷新后重新输入同一操作可核对原结果。确认成功后再创建相同内容是新操作。关闭页签丢弃恢复键后，先查看既有任务，不承诺自动恢复原操作 ID。

新增表由 Nextcloud 迁移建立；bootstrap 先校验 PHP、执行升级、严格检查就绪，再安装/启用应用。只支持本试用 PostgreSQL，不改原有 Vault/SQLite/runtime。

验收：check_task_idempotency.py（六客户端并发/冲突/权限/后续编辑）；--verify（读重启前保存的操作）；check_tasks_response_loss.cjs（真实服务 200响应后截断交付，再导航重试）。证据保存 evidence，无密码。响应丢失不是 COMMIT 连接中断或进程崩溃的同类直接注入证据。


## 本机常见问题

这台电脑上 10/25 到 11/03 实际踩过的坑。每条写现象、知道的原因、怎么处理、不要做什么。原因写「不确定」的，是只看到了现象。

先记住两件事：

- 仓库目录 `deploy/nextcloud-trial/cognos-app` 是直接挂进 app 和 cron 容器的。**本机工作目录切到哪个分支，8780 跑的就是哪个分支的代码**，不用复制，也没有缓冲。
- 不删数据。要清掉的东西一律改名（加 `.stale-<时间>`），不删；不对卷用 `down -v`、不跑全局 prune。

### Docker Desktop 起不来（多半在电脑重启以后）

- 现象：Docker Desktop 报错退出，日志 `%LOCALAPPDATA%\Docker\log\host\com.docker.backend.exe.log` 里有 `starting services: initializing Inference manager: listening on unix://...\Docker\run\dockerInference: remove ...: The file cannot be accessed by the system`，或者同样的话指向 `docker-secrets-engine\engine.sock`。弹窗给两个按钮：Quit 和 Reset to factory defaults。
- 原因：上次没正常关，留下了旧的 Unix socket 文件，Windows 这边删不掉它们。10/25、10/28、11/01、11/03 都遇到过。
- 处理：
  1. 点 Quit（或确认 Docker Desktop 进程已经没了）。
  2. 把 `%LOCALAPPDATA%\Docker\run` 整个改名成 `run.stale-<时间>`。
  3. `engine.sock` 从 Windows 这边动不了，经 WSL 改名。在 Git Bash 里要加 `MSYS_NO_PATHCONV=1`，不然路径被改写，命令什么都不做也不报错：
     ```bash
     MSYS_NO_PATHCONV=1 wsl -d Ubuntu-24.04 -- mv -f /mnt/c/Users/lnp/AppData/Local/docker-secrets-engine/engine.sock /mnt/c/Users/lnp/AppData/Local/docker-secrets-engine/engine.sock.stale-<时间>
     ```
  4. 再启动 Docker Desktop。约 10 秒就绪；容器有重启策略，会自己回来。
  5. 如果又失败了：失败的那次启动会新建一个 `run`，把它也改名，再起一次。
- 不要：点 **Reset to factory defaults**，它会清掉镜像和卷，也就是试用库里的全部数据。也不要删 socket 文件或 `run` 目录，改名就够了。

### `tools/cognos-space-mcp/start-bridge.ps1` 一读就不见了

- 现象：在 PowerShell 里读这个文件（`Get-Content`、`& ...\start-bridge.ps1`）时提示「拒绝访问」或「找不到文件」，接着它就从工作目录里消失了，`git status` 显示 `D`。11/01、11/03 各一次。
- 原因：火绒把它隔离了（脚本里有 `-ExecutionPolicy Bypass` 和注册计划任务）。这台电脑上 Windows Defender 和火绒都开着，Defender 的检测记录是空的（`Get-MpThreatDetection` 查不到），所以是火绒。
- 处理：11/03 小林在火绒「信任区」里加了 `C:\Users\lnp\Documents\Playground\cognos\tools\cognos-space-mcp` 这一个文件夹。加完以后 `git restore` 找回，用 `Get-Content` 读一次、等几秒，文件还在，`git status` 干净。
  - 只加这一层，不要把整个仓库或 Playground 加进去，保护范围放得太宽。
  - 换了电脑、重装火绒或者信任区被清掉，它会再被隔离：去火绒菜单 →「隔离区」恢复（或者 `git restore tools/cognos-space-mcp/start-bridge.ps1`），再把上面这个文件夹加回信任区。加信任区是改安全设置，由小林自己点，不让 Claude Code 替他改。
  - 只要看内容，用 `git show HEAD:tools/cognos-space-mcp/start-bridge.ps1` 就不会碰到它。起桥也可以不用这个文件，照它的步骤手动起（见下一条）。
- 不要：把这个删除连同别的改动一起提交上去。

### 起桥、找桥、停桥

- 手动起（就是 `start-bridge.ps1` 做的事；先确认 8643 上没有别的桥）：
  ```powershell
  $repo='C:\Users\lnp\Documents\Playground\cognos'; $logDir="$repo\deploy\nextcloud-trial\evidence\claude-bridge"; $stamp=Get-Date -Format 'yyyyMMdd-HHmmss'
  $env:PYTHONIOENCODING='utf-8'; $env:COGNOS_BRIDGE_PORT='8643'; $env:COGNOS_BRIDGE_COMMANDS='1'; $env:COGNOS_SELF_MERGE='1'; $env:COGNOS_SELF_BASE='HEAD'
  Start-Process -FilePath (Get-Command python).Source -ArgumentList @('-u',"$repo\tools\cognos-space-mcp\claude_bridge.py") -WorkingDirectory $repo -WindowStyle Hidden -RedirectStandardOutput "$logDir\bridge-$stamp.out.log" -RedirectStandardError "$logDir\bridge-$stamp.err.log"
  ```
  在 Claude Code 的自动模式下，`powershell -ExecutionPolicy Bypass -File ...` 会被拦，所以照上面这样直接起。
- 看它在不在：`curl http://127.0.0.1:8643/health`。
- **进程名是 `python3.13.exe`**（经 WindowsApps 的 python 启动），按 `Name='python.exe'` 找不到它。按命令行找：
  ```powershell
  Get-CimInstance Win32_Process | Where-Object { $_.CommandLine -like '*claude_bridge*' } | Select-Object ProcessId,CreationDate
  ```
  或者看谁在听 8643：`Get-NetTCPConnection -LocalPort 8643 -State Listen`。
- **两个桥能同时占着 8643。** Python 的 HTTP 服务开了地址复用，在 Windows 上第二个桥照样能绑上，不报错。两个桥各有各的「正忙」记录，会互相踩。起新桥前先停旧的，起完确认只剩一个。
- 停桥用 `taskkill /T /F /PID <pid>`，会连它起的 Claude Code 一起结束，正在做的那件就断了。
- 日志在 `deploy/nextcloud-trial/evidence/claude-bridge/`（不进 git）。用 `Get-Content` 看时加 `-Encoding UTF8`，不然中文是乱码。

### 换了 app 版本或加了迁移以后，8780 整站 503

- 现象：`status.php` 里 `needsDbUpgrade: true`，页面 503。桥发回结果也会失败。10/28 因此丢过一次回答。
- 原因：`appinfo/info.xml` 的版本号变了，或者多了 `lib/Migration/` 里的迁移。因为目录是直接挂进去的，切分支、合并、「长进身体」自动合进来都会马上生效。
- 处理：先读一遍新迁移，再跑：
  ```bash
  docker exec -u www-data cognos-nextcloud-trial-app-1 php occ upgrade
  docker exec -u www-data cognos-nextcloud-trial-app-1 php occ app:disable cognos
  docker exec -u www-data cognos-nextcloud-trial-app-1 php occ app:enable cognos
  ```
  「长进身体」只重启 app 容器，不跑 `occ upgrade`，带迁移的改动合进来以后要手动跑。
- 切到 app 版本更低的分支（比如从 0.20.0 切回 0.18.2）时，Nextcloud 同样会提示要升级。`app:disable` / `app:enable` 会把记录的版本改成代码里的版本，提示就消失了；但新版本建的表和列还在库里，旧代码不用它们。
- 不要：在本机工作目录里随手切到 app 版本不同的分支「看一眼」，8780 正在跑这份代码。想看别的分支的文件，用 `git show <分支>:<路径>`。
- 现在 `occ db:schema:check` 会报两处，都和 Cognos 当前代码无关：Nextcloud 自己缺 `oc_federated_invites`；`oc_cognos_space_traces` 多一列 `judgment`，是身体分支（存档在 `cognos-body-20261103`）的 0.19.0 迁移留下的，可以为空。不要为了让检查变干净去删这一列，那是数据。

### 新加的路由 404（返回空的 text/html）

- 现象：代码里有这个路由，请求却是 404，内容是空的 HTML。
- 原因：app 版本没变、只加了路由时，Nextcloud 把路由表缓存在 APCu 里。`app:disable` / `app:enable` 清不掉它。
- 处理：`docker restart cognos-nextcloud-trial-app-1`，或者把版本号往上调。

### 用 occ 改了 app 配置，网页那边没变（或删了还在）

- 现象：`occ config:app:set cognos <键> --value ...` 以后，命令行读到的是新值，网页请求里用的还是旧值；`config:app:delete` 以后，网页那边还在用删掉的值。11/03 测运行租约时，一个删掉的 1 秒租约在网页那边留了好几分钟。
- 原因：网页进程缓存了 app 配置，命令行改不到那份缓存。
- 处理：改完重启 app 容器 `docker restart cognos-nextcloud-trial-app-1`，等 `status.php` 回 200。`check_run_return.py` 自己会前后各重启一次。
- 不要：改完配置不重启就去测，结论会是错的；测完忘了改回来，真实的桥地址可能还指着测试用的替身。

### Docker 里的钟和本机差几秒

- 现象：本机算出来的「一秒前」，在容器里还是「将来」。
- 处理：写检查时别拿本机时间去和容器里的时间比；要一个「已经过去」的时间，就用很早以前的值（比如 `1`）。

### 查库

PowerShell 里 `psql -c "..."` 的引号会被拆坏。用管道把 SQL 送进去：

```powershell
"SELECT count(*) FROM oc_cognos_runs;" | docker exec -i cognos-nextcloud-trial-db-1 sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB"'
```

`pg_stat_user_tables` 里的行数在数据库重启后会清零，要准确数字用 `count(*)`。

### 桥的结果待发送目录（0.20.0 以后）

- 合了「运行一定回得来」（PR #28）以后，桥做完的结果先存进 `deploy/nextcloud-trial/evidence/claude-bridge/outbox/pending/`，发回 Cognos 成功才删。`/health` 里的 `pending` 是还没发出去的个数。
- `outbox/inflight/` 是桥收下、还没做完的；桥重启时，里面记它的进程已经没了的，会报告给 Cognos 标成 unknown。
- `outbox/rejected/` 是 Cognos 明确拒收的（比如那次运行不存在），桥不再发，留着给人看。
- 不要：手动删 `pending` 里的文件，那就是还没送到的回答。Nextcloud 停着的时候它们会多起来，起来以后桥会补发，Cognos 按 result_id 只收一次。

### 以 cognos-admin 身份验证

- 试用环境自己的管理员是 `cognos-admin`，密码只在 `.secrets/admin-password`。检查脚本（`check_*.py`、浏览器脚本）自己从文件里读，不打印。
- 在 Claude Code 的自动模式下，把这个密码读进对话会被拦；要在登录后的页面里看，就用脚本登录（参照 `browser-shell.cjs` 的 `login`）。
- 不要：用小林本人的账号（`lin`、`yuriyuri`）做测试。
