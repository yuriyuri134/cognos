# Cognos 当前交接状态

更新：2026-10-13

## 2026-10-13 Hermes 统一接入与 Activity 可靠性切片（0.15.0）

- 用户明确选择：五个 Cognos participant 统一接入当前 Hermes 即可；participant 仍保留稳定身份、`activity + participant` 独立 session 和消息作者标记，不要求五套底层 runtime。
- Nextcloud Cognos 已升级到 `0.15.0`。所有 participant 默认使用 compose 配置的 `HERMES_API_MODEL=hermes-cognos`；可选 `HERMES_PARTICIPANT_ROUTE_<ID>` 只作为覆盖，不再阻止默认 Hermes 调用。
- Hermes gateway 实测：认证 `/v1/models` 返回 `hermes-cognos`；容器内对 `/v1/chat/completions` 实测 HTTP 200、返回模型 `hermes-cognos` 和 choice。
- Activity 消息新增稳定客户端 `request_id` 契约：用户消息按 `activity_id + user_id + request_id` 唯一；重复请求复用已保存 assistant 回复，处理中返回 409，失败保留原用户消息并可重试；同一 request ID 改正文拒绝。
- 用户消息成功状态与 assistant 消息插入通过同一数据库事务提交；assistant 消息的 `request_id` 为 NULL，`reply_to` 指向原用户消息，避免与用户请求幂等键混用。
- 已执行 live migration `Version001500Date20261013090000`，数据库唯一索引 `cognos_activity_msg_req_unique` 已读回；Cognos disable/enable 后 `needsDbUpgrade=false`、`maintenance=false`。
- 已验证：目标 PHP lint、Activity/Notes/ActivitySource/Hermes/新异常/新 migration；Activity/Deck/Notes JS syntax；XML；diff check；匿名 participants、Notes search、Activities 均 401；`/notes/search` live router 为 `cognos.notes.search`。
- 独立代码审查发现三个问题，已修复并验证通过：1) Activity 前端 CSRF token 被 spread 覆盖（js/activity-page.js 调整 spread 顺序）；2) 失败消息重试使用 Activity 当前参与者而非消息原参与者（HermesAgentService.php 改用 `message['participant_id']`）；3) 并发重试抛出 `DomainException` 而非已定义的 `ActivityRequestInProgressException`（现已抛出正确异常，Controller 返回 409）。修复后无剩余阻断问题。
- 认证浏览器验收尚未完成：当前公网登录使用 vault 条目后返回 Nextcloud `Wrong login or password`；没有读取或使用聊天中的密码，也没有继续撞击登录。

## 2026-10-11 Agent 参与者身份切片（0.14.0）

- Nextcloud Cognos 已增加 `cognos_participants`，当前稳定身份为 Codex、Gemini、Gemini-B、DeepSeek、Pi；身份与底层 runtime/model 分离，不能用显示名冒充模型身份。
- `cognos_activities` 和 `cognos_activity_messages` 保存 `participant_id/participant_name`；Activity 创建、材料→Activity、Deck→Activity 均要求用户明确选择参与者，Activity 页面显示当前参与者并允许切换。
- Hermes 调用按 `activity + participant` 生成独立 session key，并发送参与者元数据；历史消息保留原参与者标记，Agent 输出默认仍是候选，不自动成为任务、决定或项目事实。
- 已实际验证：Cognos 0.14.0 enabled；`needsDbUpgrade=false`；参与者表/字段存在；五条默认参与者记录已读回；匿名 participants、Activity、Notes 路由均返回 401；六个 PHP 文件、三个 JS 文件、XML、diff check 通过。
- 尚未证明：认证后的公网浏览器旅程、材料/Deck 创建 Activity 后的页面操作、Hermes 成功回复的真实落库刷新、每个 participant 到各自 Codex/Gemini/DeepSeek/Pi runtime 的真实路由。当前 Nextcloud `HermesAgentService` 的实际 API endpoint/model 仍由统一 `HERMES_API_URL/HERMES_API_MODEL` 配置提供；不能把身份目录称为五个 runtime 已迁入。


- 最终产品底座：`deploy/nextcloud-trial/` 中的 Nextcloud 自部署试用环境。
- 公开底座入口历史地址：`https://choir-bestsellers-calls-labor.trycloudflare.com/`。它曾指向 Nextcloud `app:80`，不是原生 FastAPI 首页；当前隧道已失效。
- 当前原生 FastAPI：`web/`，本机 `127.0.0.1:8765`。此前的 `reuters-orlando-prefix-passing.trycloudflare.com` 是直接暴露 FastAPI 的临时 Quick Tunnel，已在 2026-10-08 审计后停止；它不是当前可用入口。FastAPI 是 Cognos 行为验证原型，不是最终产品底座。
- 唯一主代码仓库：`C:\Users\lnp\Documents\Playground\cognos`。

## 2026-10-09/10 Nextcloud 入口与迁入边界校正

- Cognos app 已升级到 `0.11.2`，并在 `appinfo/info.xml` 注册为同一 Nextcloud 内的两个原生入口：`Cognos` → Activity，`材料` → Notes 起点。它们不是第二个服务、第二套登录或外部站点。
- Deck 卡片详情页的“围绕此任务继续”成功后直接显示“打开 Cognos Activity”链接；材料页支持按当前 Nextcloud 账号建立材料空间、创建材料、搜索并从材料建立 Activity。
- 现场证据：`occ upgrade` 成功将 Cognos `0.10.0 → 0.11.2`；`status.php` 返回 `installed=true, maintenance=false, needsDbUpgrade=false`；公网未登录访问 Notes 页面、Notes API、Activity API 均返回 401；Cognos 前端脚本 HTTP 200；XML、JS、PHP lint 通过。
- Notes 已真正切换到 Nextcloud PostgreSQL：新增 `oc_cognos_note_spaces`、`oc_cognos_notes`、`oc_cognos_note_links`；Activity 表新增 `source_type/source_id`。Cognos app 不再读取或写入 `.cognos-state/notes.db`、`activities.db`，也不再通过 PDO/SQLite 绕过 Nextcloud 身份。
- 从材料进入 Activity 时保存不可变 `content_hash + revision + content` 快照；同一材料同一版本重复进入复用 Activity，材料修订后建立新的 Activity，旧快照不变。来源 Activity 查询按当前账号 UID 隔离。
- 必须继续区分“已接入 Nextcloud”和“仍在 FastAPI 原型”：Notes 材料起点与 Deck→Activity 来源快照已经接入；第一发生场/raw bootstrap、FastAPI auth/org/project/inquiry 数据模型、完整认知 Activity runtime/context/participant 体系，以及 `phase0` 的 Pi/Codex/Gemini/DeepSeek runtime 适配尚未迁入。
- 当前 Nextcloud `continueActivity` 仍只持久化用户输入为 Activity run，没有调用 Pi 或其他模型 runtime；不能把它称为 Nextcloud 内已完成 AI 继续。认证后的真实浏览器创建/修订/来源快照旅程仍需补双账号或管理员会话验收。

## 已从 FastAPI 原型验证的行为

1. 用户建立个人笔记空间，空间按账号隔离。
2. 笔记保留来源元数据和原始内容；原始来源不被覆盖。
3. 用户明确选择 Agent 后，可以围绕当前笔记对话；Agent 实际收到当前笔记正文和有界对话上下文。
4. Agent 故障时，用户消息保留，返回可恢复错误。
5. 用户选择“围绕此起点继续”并选择项目，可从笔记创建 Activity；Activity 保存不可变正文快照及 `source_ref(note_id, space_id, source)`。
6. 原笔记后续修改不改变已启动 Activity 的起点快照。
7. 这些是产品行为证据，不是最终 FastAPI 架构。

## 下一步产品动作

把上述行为映射到 Nextcloud 底座：

- 账号、成员、项目、文件、权限优先复用 Nextcloud/Deck/Talk/Collectives/Team folders；
- Cognos 扩展只增加空间起点、来源引用、Activity/Agent 接续等特有能力；
- 不把 FastAPI 的 notes SQLite、auth、org 数据库迁移成第二套最终真相源；
- 不把个人笔记自动公开给团队；
- 不自动把 Agent 输出变成任务/决定/项目事实；
- 原始来源和 Activity 起点版本必须可回指。

## 当前第一个迁移切片

实现 Nextcloud Cognos 扩展中的：

`Nextcloud 可访问的笔记/文件起点 → Cognos Activity 起点快照 → 来源回指`

先不做：完整个人笔记系统、复杂空间本体、三空间菜单、自动分享、自动发布、后台 Agent。

## 当前施工结果

- 2026-10-08/09 全面运行审计确认：源码仓库只有一份，主实例为 `primary-local`（8765）；Nextcloud 试用状态仍保留在隔离目录但当前未运行；`8775/8776/8780` 及临时测试端口均未监听。主 raw 与 runtime continuity 未迁移或改写。完整审计报告为 `work/cognos-instance-audit-2026-10-08.json`，只保存状态、计数和哈希，不保存对话正文。
- 直接 FastAPI Quick Tunnel 已停止，因为它会公开 `/api/bootstrap` 等读取接口；恢复异地试用必须使用仅转发 Talk webhook 的受限代理或重新完成权限审计，不能直接把 8765 暴露到公网。
- Nextcloud Cognos app 已开始增加 `0.9.0` 的 Activity 起点投影：从有权限读取的 Deck 卡片创建 Cognos Activity 快照，保存 `board_id/card_id`、操作者、标题、描述、列、完成状态和创建时间。
- 新路由：`POST /index.php/apps/cognos/activities/from-card/{cardId}`、`GET /index.php/apps/cognos/activities`。
- 新表迁移：`cognos_activities`，由 `Version000700Date20261008000000.php` 创建。原 Deck 卡片不被修改，不复制 FastAPI notes/auth/org 数据库。
- 当前只能称为源码切片：本机没有 PHP CLI，Docker Desktop Linux engine pipe 不存在，尚未执行 PHP lint、Nextcloud migration、真实权限 HTTP 或浏览器验收。

## 当前新增入口

- Deck 卡片详情页注入 `activity-start.js`，提供“围绕此任务继续”按钮。
- 按钮调用 `/index.php/apps/cognos/activities/from-card/{cardId}`，成功后只显示已保存的 Activity ID；没有伪造尚不存在的 Activity 详情页链接。
- 当前静态验证通过：JS syntax、XML、diff；真实 Nextcloud 页面验证仍等待 Docker/PHP 环境。

## 意图驱动多账号探索状态

- 第一轮隔离 FastAPI sandbox 角色：founder、member、outsider、AI participant。验证了未定型材料、笔记 Agent 上下文、笔记→Activity 来源回指、私有笔记不自动进入成员账号、项目内显式召唤 AI。
- 第一轮发现并修复一个权限断点：非项目成员原先可读取 `/api/projects/{project_id}/messages`（HTTP 200）；现在要求登录且必须是项目成员，否则 HTTP 403。定向回归为 `27 passed`。
- 第二轮恢复旅程发现重复点击“笔记→Activity”会创建两个 Activity；现按同一 `note_id + space_id + project_id + source` 回用已有 Activity，重复请求返回同一 ID 并带 `reused=true`。定向测试和隔离重跑通过。
- 第二轮仍待处理：Agent 运行失败后用户再次发送相同内容会产生两条用户消息。暂不自动去重，因为这可能是有意重试；下一轮先改善“消息已保存但 Agent 无回复”的可见状态，再决定重试语义。
- 下一轮角色/旅程：new_user 首次进入、member 刷新/重新登录后继续、runtime failure 明确恢复、Activity 结果返回项目、成员看到什么而不看到什么、同一来源不同项目是否应创建独立 Activity。

## Agent 失败恢复轮

- 隔离旅程确认后端行为：Agent runtime 失败时用户消息已经保存，接口返回 HTTP 502，并说明“消息已保存在笔记空间，但 Agent 暂不可用；可重试”。刷新空间后消息仍存在。
- 原 UI 断点：前端 catch 只显示错误，没有重新读取空间；用户看不到刚保存的消息，无法判断是否安全重试。
- 窄修复：`notes.js` 为错误保留 HTTP status；收到 502 后重新打开当前空间，再显示后端恢复提示。没有自动重发、没有自动去重，也没有把失败误报为成功。
- 验证：`node --check web/frontend/notes.js` 通过；`test_notes_api.py test_org_api.py test_server.py` 定向回归 `27 passed`；`notes_api.py` compile 与 `git diff --check` 通过。
- 未决产品问题：用户是否需要一个明确的“重试上一条”动作，而不是再次编辑/发送同样文本。当前保留两条用户消息的可能性，因为第二次发送可能是有意新尝试；下一轮需用新用户角色和刷新/重新登录旅程判断。

## 多 agent 审计后的权限与版本修复

- 三个独立审计完成。发现原先“项目消息非成员 403”并不代表 Activity 安全：Activity 详情、预览、上下文、文档、历史等读取接口可被未登录/非成员读取；写入检查还会通过 `join_project()` 自动把调用者加入项目。
- 已修复：Activity 列表、详情、预览、上下文、文档、取材预览、历史浏览、经验候选和写入路径统一要求登录且已是项目成员；不再用自动加入项目作为授权检查。笔记进入 Activity 也要求目标项目已有成员资格。
- 已修复：笔记 PATCH 绑定空间所有者；Notes SQLite 增加 `revision` 和 `content_hash`。同一笔记同一项目同一版本复用 Activity；笔记修订后在同项目创建新的 Activity，旧快照不变。
- 新增回归覆盖非成员 Activity 读取/继续、越权笔记修改、笔记版本分叉和旧/新快照。相关回归 `61 passed, 5 subtests`；全量 `web/tests` 为 `197 passed, 1 skipped, 5 subtests`。
- 独立多账号旅程验证：修订后新 Activity 为 `200` 且 ID 不同；旧/新正文分别为“第一版/第二版”；非成员 Activity 详情和继续均为 `403`。
- 下一步：把相同权限矩阵和版本旅程在 Nextcloud 底座复跑；当前 PHP/Docker 环境仍不可用，不能把 FastAPI 结果升级成 Nextcloud 验收。

## 环境阻碍已处理与明确重试

- 用户明确要求：遇到 Docker/PHP 等阻碍必须立即明显报告并尝试解决；不应继续沿用旧阻碍或把 FastAPI 结果冒充 Nextcloud 验收。
- 重新检查后 Docker Desktop 已恢复：Nextcloud app、cron、Postgres、Redis 均运行，app 监听 `127.0.0.1:8780`。主机没有 PHP CLI，但使用 Nextcloud app 容器内 PHP 完成 lint 和 `occ` 操作。
- 实际执行 `docker compose exec -T app php occ upgrade`：Cognos `0.8.0 → 0.9.0`，数据库 schema 更新、code integrity check、maintenance mode 恢复均成功；`needsDbUpgrade=false`，`occ db:schema:check` 报告 live schema matches expected schema；Nextcloud `/index.php/login` 和 `/status.php` 均 HTTP 200。
- Agent 失败恢复已升级为明确动作：新增 `POST /api/notes/spaces/{space_id}/messages/{message_id}/retry`；原用户消息不复制，成功回复以 `reply_to` 指向原消息，失败继续保留原消息并可再次重试。笔记空间 UI 显示“重试上一条”。
- 当前 FastAPI 全量回归：`198 passed, 1 skipped, 5 subtests passed`；`notes.js` node syntax check 通过。Nextcloud 容器内核心新增 PHP 文件 lint 通过，真实 Nextcloud 已完成升级/数据库检查，但 Deck 卡片点击与多账号权限浏览器旅程仍需在底座页面继续验收。

## 目标模式安全基线修复轮

- 多 agent 审计实际复现了 FastAPI 高风险缺口：匿名读取 `/api/bootstrap`、`/api/instructions`、项目决定；非成员读取项目决定；非成员写入项目决定。
- 已修复并验证：`bootstrap`/`instructions` 要求登录；组织列表只返回当前账号所属组织；组织详情/成员详情要求组织成员；项目 conversations、conversation messages、decisions、work-items、timeline 要求项目成员；非成员写入决定现在返回 403，避免进入业务写入或 runtime 后才产生 502。
- 当前隔离权限矩阵实际输出：匿名 `[401, 401, 401]`；非成员读取/写入 `[403, 403]`。定向权限/Notes/Activity/组织回归 `70 passed, 5 subtests`；相关 Python compile 与 diff check 通过。
- 这是 FastAPI 行为原型的安全基线，不等于 Nextcloud 权限已统一；公网 FastAPI tunnel 继续保持关闭。

## 权限完整矩阵与自动入组移除（2026-10-09 续）

- 逐路由独立账号矩阵实测：修复前匿名可读 inquiry 列表/详情；已登录**非成员**可读并写入 inquiry、项目消息、组织成员、项目成员与职位。根因 `join_project()` 兼做授权与自动加入，写一次即取得成员身份。
- 已修复并实测：25 条路由对匿名全部 401、对已登录非成员全部 403；"写一次后即可读取"的链条消除。新增共享守卫 `require_org_member()` / `require_project_member()`（从不自动加入），Activity/Project/Inquiry/Org 四处重复规则统一。
- 合法入组路径完整：邀请 → 接受（成为组织成员）→ 被加入项目 → 读写/探究 200；未入组者不能给自己开位子；第三方全程 403。
- 回归 `199 passed, 5 subtests passed`（此前 `193 passed / 6 failed`）。6 个失败逐个定性：3 个断言旧的不安全读契约，3 个依赖已移除的自动入组；均改为断言新契约或走正式入组路径，未批量改绿。
- 仍在、未并为本轮结论：`/api/bootstrap` 对任何已登录账号仍返回完整 raw 历史与参与说明全文及服务器路径；`/api/instructions` 同理。二者现已要求登录，但尚未做到项目级隔离。公网 tunnel 保持关闭。

## 2026-10-09 全面审计后的当前状态

- 主实例已用项目固定依赖从 `phase0/.venv` 重新启动，`8765/api/health=200`；当前隔离全量回归为 `250 passed, 18 subtests passed`。Python compile、JS 语法、PowerShell AST、Compose config 和 diff check 通过。
- `requirements.txt` 已固定 SQLAlchemy 2.0.52、FastAPI 0.141.1、Uvicorn 0.52.1、HTTPX 0.28.1、Pydantic 2.13.4，避免测试环境、uv 临时环境和主服务使用不同 FastAPI 路由行为。
- 审计仍发现 FastAPI 原型有公开读取接口：`/api/bootstrap` 会返回主 raw 内容，组织/成员/项目部分 GET 也未统一要求登录。当前直接公网 tunnel 已停止，因此这些接口现在只在本机 8765 可达；重新公网开放前必须收紧读取权限或只使用受限 webhook proxy。
- `web/.env` 中的 Gemini API key 曾在本次诊断输出中暴露，必须在 Gemini 控制台立即撤销/轮换；后续诊断不得打印完整配置。`AppConfig` 的 repr 已改为不显示 key/secret。
- 主 raw 哈希仍为 `50274c21b527234e012b578e120d40436c2e49353f4c934310844d36a0e884e0`；Codex/Gemini/Gemini-B/DeepSeek continuity 文件哈希保持不变。隔离 Nextcloud 状态仍保留但未运行，Docker/PHP 验收仍未完成。

## 证据边界

- FastAPI 定向测试曾通过 26 项，证明原型行为。
- Nextcloud 任务/活动底座已有独立 P1-P14 证据，但不等于新笔记起点切片已实现。
- Nextcloud Docker 当前是否运行必须每次现场检查；不能沿用历史公网 URL 或旧容器状态。
- 每个迁移切片必须单独提供 PHP/JS lint、API 权限、真实底座 HTTP/浏览器和来源持久化证据。
