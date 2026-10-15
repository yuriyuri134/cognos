# Cognos Agent 接续 — 交接文档

写于 2026-10-16。只记录能从代码、文件、数据库、命令输出核实的事实，每条附依据。
未核实的内容集中在 §6，不在正文里当事实陈述。

---

## 1. 原始目标（用户原话，未改写）

- Cognos 整个项目 = Nextcloud，不是分离的
- 笔记/组织/项目/学习四部分，依照 Nextcloud 既有能力融合重整
- Agent 是贯穿能力，不是独立系统
- Agent 接续当作 harness 来做

---

## 2. 已核实的现状

### 2.1 可达性（本次实测）

| 项 | 结果 | 依据 |
|---|---|---|
| Docker Desktop | 未运行 | `cmd.exe /c "docker compose ps"` → `open //./pipe/dockerDesktopLinuxEngine: The system cannot find the file specified`；`docker version` 同样报错；`ls /var/run/docker.sock` → No such file |
| Nextcloud `127.0.0.1:8780` | 不可达（http=000） | `curl -m 5 -o /dev/null -w "%{http_code}" http://127.0.0.1:8780/` |
| Hermes API `127.0.0.1:8642` | 不可达（无响应） | `curl -m 5 http://127.0.0.1:8642/health` 无输出 |
| 监听端口 | 无 8642/8780 | `ss -ltn` 只有 53/127.0.0.54:53/127.0.0.53:53 |

→ 因此 PostgreSQL 的 `oc_cognos_*` 表本次**无法查询**，下文 Nextcloud 表结构的依据是迁移文件，不是 live 查询。

### 2.2 git 状态

- 分支 `master`，HEAD `26fd62b`，工作区干净（`git status --short` 无输出）。
- HEAD 提交时间：2026-10-13 09:49:59 +0800（`git log -1 --format="%H %ci %s"`）。
- 近期提交：`26fd62b` → `f47e5cb`(0.15.0) → `084b5b0` → `327e4b7` → `0f6dd25`（`git log --oneline -5`）。
- 应用版本 `0.15.0`（`cognos-app/appinfo/info.xml:8`）。

### 2.3 数据库表与字段

#### A. Nextcloud 应用表（PostgreSQL；依据 = 迁移文件）

**`cognos_activities`**（`lib/Migration/Version000700Date20261008000000.php:15-26`）
id(64), actor_uid(64), actor_name(255), board_id(bigint,notnull), card_id(bigint,notnull), title(255), source_snapshot(text,notnull), created_at(bigint,notnull)；主键 id；索引 `cognos_activities_actor(actor_uid,created_at)`、`cognos_activities_source(board_id,card_id)`
追加：`source_type`(32)、`source_id`(128) — `Version001000Date20261010010000.php:16-19`
追加：`status`(32, default `in_progress`)、`updated_at`(bigint, default 0) — `Version001200Date20261011030000.php:17-20`、`Version001300Date20261011040000.php:21-28`
追加：`participant_id`(64)、`participant_name`(255) — `Version001400Date20261011060000.php:31-34`

**`cognos_activity_messages`**（`Version001100Date20261010020000.php:15-23`）
id(64), activity_id(64), user_id(64), role(16), content(text), created_at(bigint)；主键 id；索引 `cognos_activity_message_activity(activity_id,created_at)`
追加：`state`(16, default `succeeded`)、`request_id`(64)、`error_text`(text)、`reply_to`(64) — `Version001200Date20261011030000.php:29-38`
追加：`participant_id`(64)、`participant_name`(255) — `Version001400Date20261011060000.php:44-47`
追加：唯一索引 `cognos_activity_msg_req_unique(activity_id,user_id,request_id)` — `Version001500Date20261013090000.php:17-21`

**`cognos_participants`**（`Version001400Date20261011060000.php:17-23`）
id(64), display_name(255), kind(32), runtime_key(64), description(text), created_at(bigint)

**不存在** formation / tension / envelope / move / context_snapshot 字段或表。
依据：`grep -rn "envelope|formation|tension" cognos-app/` 唯一命中 `lib/Command/ImportObsidian.php:44`（文件扩展名判断），与协议无关。

#### B. SQLite（`deploy/nextcloud-trial/.cognos-state/`；依据 = python3 读 `sqlite_master`）

- `activities.db`：表 `activity_revisions` / `activity_runs` / `activity_moves` / `activity_context_snapshots` / `cognos_refs` / `cognos_activity_messages` —— **全部 rows=0**
  - `activity_context_snapshots` 字段：id, activity_id, run_id, call_index, activity_revision, context_json, rendered_prompt, rendered_prompt_hash, created_at（建表语句在 `web/activity_store.py:84`）
  - `activities.db` 里的 `cognos_activity_messages` 字段为 id, activity_id, user_id, role, content, **model**, created_at —— 与 PostgreSQL 版不同（无 state/request_id/reply_to/participant_*），属 FastAPI 侧 schema
- `notes.db`：note_spaces=2 行，notes=1368 行，note_links=4073 行，note_messages=0 行
  - `note_spaces` 两行同名为 `Obsidian Import`：`d88f0df3-0de0-4eb7-be1c-a03c65097282`（684 notes）、`fecf9807-f062-4b7a-b11d-a7532238e86e`（684 notes）
- `org.db` / `inquiry.db` / `auth.db`：所有表 rows=0
- `talk_bridge.db`：`talk_bridge_events` 4 行
- `notes.db.backup.20261010_004428`：notes=0 行
- `cognos-app/` 代码不引用 `activities.db` 或 `.cognos-state`（grep 在 `cognos-app/`、`compose.yaml` 内无命中）→ Nextcloud 应用不读这些 SQLite；但这些文件是否仍有服务写入，见 §6

### 2.4 HermesAgentService 当前行为（依据：`lib/Service/HermesAgentService.php` 行号）

**`chat()` L19-99**
1. 取当前 Nextcloud 用户；无用户 → `Authentication required`
2. `getActivityForUser()`（L265-283）按 `id + actor_uid` 取 Activity，查不到 → `Activity not found`
3. 要求 Activity 有 `participant_id`，否则 `Activity participant is not selected`
4. `participantRouteModel()`（L256-259）：读 `HERMES_PARTICIPANT_ROUTE_<大写ID>`，为空则回落 `HERMES_API_MODEL`（默认 `hermes-cognos`）；都为空 → `ParticipantRuntimeUnavailableException`
5. 取最后一条 `role=user` 的消息作为本次输入；空 → `Message is required`
6. 组装 `requestMessages`（L47-57）：`[system=buildSystemPrompt()]` + 历史中 `state==='succeeded'` 的消息 + 本次用户消息
7. `normalizeRequestId()`（L154-163）：空则生成 32 位 hex；非空须匹配 `^[A-Za-z0-9._:-]+$` 且 ≤64 字符
8. 幂等：`findUserMessageByRequest()`（L165-175）按 `activity_id + user_id + request_id` 查已有用户消息；命中且正文相同 → `replayExistingRequest()`（L177-205）返回已存的 assistant 回复（`replayed=true`）；正文不同 → `request_id 已用于另一条消息`
9. `saveMessage()`（L379-397）插入用户消息，`state='pending'`；插入冲突时再查一次幂等键
10. `callHermes()`（L333-377）成功后 `persistSuccessfulReply()`（L399-431）；任何异常 → `markMessageFailed()`（L433-441）+ 抛 `ActivityChatException('Hermes 暂时不可用；你的输入已保存，可以重试。')`

**`callHermes()` L333-377**
- URL：`getenv('HERMES_API_URL')`，默认 `http://host.docker.internal:8642/v1/chat/completions`
- Key：优先 `HERMES_API_KEY_FILE`（默认 `/run/secrets/hermes_api_key`）文件内容，否则 `HERMES_API_KEY` 环境变量；都空 → `Hermes API is not configured`
- Body：`{model, messages, stream:false}`；model = 参数 ?: `HERMES_API_MODEL` ?: `hermes-cognos`
- Headers：`Authorization: Bearer <key>`、`Content-Type: application/json`、`Accept: application/json`、`X-Hermes-Session-Id`、`X-Cognos-Participant-Id`
- 超时 180s
- 非 2xx 或响应非数组 → `RuntimeException('Hermes API request failed')`
- `choices[0].message.content` 非字符串或 trim 后为空 → `RuntimeException('Hermes returned no response')`
- 返回 `{content, model, session_id}`

**`persistSuccessfulReply()` L399-431**（单事务）
- `UPDATE` 用户消息 `pending → succeeded`、清 `error_text`，带条件 `id + activity_id + user_id + state='pending'`；影响行数 ≠1 → 抛 `User message is no longer pending`
- `INSERT` assistant 消息：`request_id = NULL`，`reply_to = 用户消息 id`，`state='succeeded'`
- `commit()`；异常 `rollBack()` 后重抛

**`buildSystemPrompt()` L285-310**
输出为中文纯文本。组成：参与者名 → 「你不是匿名助手，也不是已确认事实的发布者」→「只把回答作为候选理解、提议或待验证问题；不要自动创建任务、决定或项目事实」→ `Activity 标题` → `来源类型`；`source_type==='note'` 时附 `材料标题`/`材料正文`，`==='nextcloud_deck_card'` 时附 `项目/任务卡片标题`/`卡片描述`/`看板`/`列表`；结尾「原始来源是上下文，不是已经确认的事实；请区分候选建议和用户已确认内容。」

**`buildConversationHistory()` L312-331**
`SELECT id, role, content, state, reply_to FROM cognos_activity_messages WHERE activity_id=? ORDER BY created_at ASC LIMIT 50`；`chat()` 只纳入 `state==='succeeded'` 的行。

**`retryFailedMessage()` L207-254**
仅允许 `role=user` 且 `state='failed'` 的消息；用 `UPDATE ... failed → pending` 抢占（≠1 行则抛 `ActivityRequestInProgressException`）；使用**消息自身**的 `participant_id` 路由；历史中跳过失败消息本身；重新调用失败则再次标记 failed。

**`chatRoom()` L101-130**（Talk 路径）
独立 system prompt（房间 token + 可选项目上下文 + 「不要臆造成员权限或项目事实」），调用 `callHermes` 且不传 model/participantId；session key = `'talk_' . sha256(roomToken)`（L121）。

**参与者固定身份**（`lib/Service/ActivitySourceService.php:356-360`）
`codex`(Codex/工程与实现)、`gemini`(Gemini/探索与发散)、`gemini-b`(Gemini-B/独立连续性)、`deepseek`(DeepSeek/分析与反思)、`pi`(Pi/执行与现场)，均为 `kind='ai'`。
`compose.yaml:50-57`：`HERMES_API_URL=http://host.docker.internal:8642/v1/chat/completions`、`HERMES_API_MODEL=hermes-cognos`、五个 `HERMES_PARTICIPANT_ROUTE_*` 全部 = `hermes-cognos`、`HERMES_API_KEY_FILE=/run/secrets/hermes_api_key`。

### 2.5 Activity 页面空白

**路由与渲染链（已核实）**
- `appinfo/routes.php`：`activity#page` → `/activities/{id}` GET（id 必须匹配 `[a-f0-9]{32}`）；`activity#listPage` → `/activities` GET
- `lib/Controller/ActivityController.php:36-46`：`page()`、`listPage()` 均带 `#[NoAdminRequired]` `#[NoCSRFRequired]`；`page()` 返回 `new TemplateResponse('cognos','activities',[])`
- `templates/activities.php`：`Util::addScript('cognos','activity-page')` + `addStyle('cognos','activity-page')`，容器为 `<div id="cognos-activities-page">`
- `js/activity-page.js`：322 行；`apiBase = '/index.php/apps/cognos'`；`root()` = `document.querySelector('#cognos-activities-page') || document.body`
- `lib/Listener/TaskFeedbackListener.php`：当响应 app 为 `deck` 或 `cognos` 时加载 8 个脚本；且仅当 app 为 `cognos` 且 request URI 含 `/activities` 时**再**加载 `activity-page.js` → 该脚本在 Activity 页会被 `addScript` 两次（模板一次、listener 一次）

**已知原因（历史，有 diff 依据）**
HEAD 之前 `page()`/`listPage()` 没有 `#[NoCSRFRequired]`；`git show 26fd62b` 的 diff 显示本次才加上，commit message 写明「修复 412 错误」。

**本会话曾观察到、本次无法重现的现象**
- 压缩前的会话里曾用浏览器检查 Activity 页，结果 `document.querySelector('#app-content')` 不存在、页面无 Cognos 内容（当时的判断是缺 CSRF 豁免导致 412 被拦）。
- 修复后同一会话曾记录到 `#cognos-activities-page` 渲染、多处 cognos 脚本加载、`/activities/data` 返回 200。
- **本次无法复现或否定**：Docker 未运行，Nextcloud 不可达（§2.1）。所以「当前是否仍空白」= 原因未知/未核实（§6）。

### 2.6 信封协议是否已进入发给 Hermes 的 system prompt

**否。**

- `buildSystemPrompt()`（`HermesAgentService.php:285-310`）全文为中文纯文本，无 JSON 输出要求，无 `formation_updates` / `tension_updates` / `move` / `pause` 字样。
- system 消息唯一的构造点在 `chat()` L47-52（以及 `retryFailedMessage()` L~226-230 的等价构造）。
- `grep -rn "envelope|formation|tension|pause" cognos-app/` 无协议相关命中（唯一命中是 `ImportObsidian.php:44`）。
- **相关但不同**：协议文本作为**材料**存在于导入的 Obsidian 笔记里 —— `notes.db` 中有 30 条笔记含 `formation_updates` 字样，例：title=`codex-35ceb3df0554fa95bbecb6d2`，正文含 `{"message":..,"formation_updates":[{"text":..,"refs":[]}],"move":{..},"pause":{"reason":..}}` 的示例与约束说明。这是笔记内容，不是发给模型的 system prompt。

### 2.7 Hermes 实际回复过的格式（原始回复）

可核实的真实模型回复文本只有一条，在 `deploy/nextcloud-trial/.cognos-state/talk_bridge.db` 的 `talk_bridge_events`：

```
event_key = talk:riirzokb:9
status    = completed
reply     = 已收到这条远程桥接验收消息。
created_at= 2026-10-07T21:21:19.410026+00:00
```

→ **纯文本，不是 JSON。** 但它走的是 FastAPI 的 Talk 桥接路径（`web/talk_bridge.py`），不是 Nextcloud `HermesAgentService` 路径。

同表另 3 行：
- `talk:body:f1f74e519e13...` status=`ignored`，reply 空，2026-10-07T21:19:53Z
- `talk:riirzokb:8` status=`failed`，error=`RuntimeUnavailableError: Gemini 返回异常 HTTP 400：{"error":{"code":400,"message":"User location is not supported for the API use.","status":"FAILED_PRECONDITION"}}`，2026-10-07T21:20:13Z
- `talk:body:113da536c208...` status=`ignored`，reply 空，2026-10-07T21:50:40Z

其他记录：
- `docs/Cognos-当前交接-精简版.md:9`：「容器内对 `/v1/chat/completions` 实测 HTTP 200、返回模型 `hermes-cognos` 和 choice」—— 该文档未记录 content 原文。
- Nextcloud Activity 的 assistant 消息原文：**本次一条都没取到**。PostgreSQL 不可查；`.cognos-state/activities.db` 的 `cognos_activity_messages` 为 0 行；`evidence/` 下无 `role=assistant` 记录（`grep -rln '"role": "assistant"' evidence/` 无命中）。

---

## 3. Talk / Deck / Collectives 检索

### 做过的

**已安装 app（安装证据，非权限一致性或旅程证据）**
`ACCEPTANCE.md:68`：管理员 OCS enabled apps 实际返回 Deck、Talk（spreed）、Collectives、Team folders（groupfolders）均已启用；Deck capability `version=1.19.0`。

**Deck — API**
- 验收脚本使用的端点：`PUT /ocs/v2.php/apps/deck/api/v1.0/cards/{id}`、`PUT /ocs/v2.php/apps/deck/api/v1.0/stacks/{id}/done`、`POST /index.php/apps/deck/cards/{id}/archive`（依据：`check_task_edit.py:18`、`check_task_lifecycle.py:20`、`check_task_status_readonly.py:21`、`check_task_status.py:12`）
- `cognos-app` 自身注册的 Deck 相关 OCS 路由（`appinfo/routes.php`）：`PUT /api/v1.0/cards/{cardId}`（edit）、`POST /api/v1.0/cards/{cardId}/assign`、`PUT /api/v1.0/cards/{cardId}/unassign`、`POST /api/v1.0/cards`（create）
- `ActivityController` 引用 `OCA\Deck\Service\PermissionService`（`lib/Controller/ActivityController.php` use 段）

**Deck — 数据结构（实际被 Cognos 读取的部分）**
- Activity 保存卡片来源快照：`cognos_activities.board_id` / `card_id` 为 bigint（`Version000700...:19-20`），快照正文存 `source_snapshot`(text)
- 取用时读 `snapshot['title']` / `['description']` / `['board_id']` / `['stack_title']`（`HermesAgentService.php:302-306`）

**Talk**
- 定位是 webhook 集成边界：`deploy/nextcloud-trial/README.md:38-60` 说明启动「只转发 Talk webhook 的代理」、`install-talk-bot.ps1 -WebhookUrl .../api/integrations/nextcloud-talk/webhook` 安装 bot、`stop-public.ps1` 清理、URL 变化后需 `-ReplaceExisting` + 重新 `talk:bot:setup`
- 触发方式：`@Cognos @Gemini 你的问题`；未提及 bot 的普通消息不调用模型（`README.md:52`）
- Nextcloud 35 没有 `talk:room:list` 命令，房间 token 需从 Talk 界面或创建房间命令输出取得（`README.md:46`）
- `cognos-app` 内 Talk 相关代码只有 `HermesAgentService::chatRoom()`（L101-130）和 session key（L121）
- Talk 重试去重：按签名、事件 ID、独立 SQLite 投影去重；回复投递失败重试已生成回复而不重新调用模型（`README.md:52`）

**Collectives**
- 仅 `ACCEPTANCE.md:68` 的「已启用」记录。

### 没做过的（明确：未做）

- **Collectives 的 API 与数据结构检索：未做。** 依据：`cognos-app/` 与 `deploy/nextcloud-trial/check_*.py` 中没有任何 Collectives 代码引用（`grep -rn "collectives" cognos-app/ -i` 无命中）
- **Talk 的 API 与数据结构检索（房间/消息/参与者字段、webhook payload 结构）：未做。** 现有 Talk 记录只覆盖 webhook 安装、bot 提及行为、去重策略
- **Deck/Talk/Collectives/Files 四者之间的对象关联与权限一致性检索：未做。** `ACCEPTANCE.md:104`、`docs/Cognos-v3-施工台账.md:386` 都只声明覆盖了 Deck，未把结论升级到跨 app

### 四部分融合方案

**尚无。** 现有唯一可核实的实现是入口链接与一个半成品表单：

- `templates/home.php` 四个入口：笔记 → `/index.php/apps/cognos/notes`；组织 → `/index.php/apps/talk/`；项目 → `/index.php/apps/deck/`；学习 → `/index.php/apps/cognos/activities`
- 同页「新建空间」按钮 + 四个 `data-space-type`（`notes` / `organization` / `project` / `learning`）
- `js/home.js`（53 行）实际只实现 `notes` 类型：其他类型点击后只显示「这个类型的空间入口已保留，具体成员/项目对象将在对应底座中建立。」（`js/home.js:33-37`）并 return；`notes` 类型 POST `/index.php/apps/cognos/notes/spaces` 后跳转 `/index.php/apps/cognos/notes`（`js/home.js:38-47`）
- 无任何文档或代码描述四部分之间的对象关系、状态流转或共享结构（`grep -rln "四个部分|四部分|四空间" docs/ *.md deploy/` 唯一命中的是 `notes.db` 的笔记内容，非设计文档）

---

## 4. 已定的决定（只写用户明确同意的）

| 决定 | 依据 |
|---|---|
| 先检索 FastAPI 原型的 Formation / Move / Context Assembly 设计，再决定 PHP 版实现范围 | 本会话用户回复「可以，继续」（对应此前提出的选项 B） |
| 五个 Cognos participant 统一接入当前 Hermes 即可；participant 保留稳定身份、`activity + participant` 独立 session、消息作者标记 | `docs/Cognos-当前交接-精简版.md:7`（2026-10-13 记录「用户明确选择」） |
| 先试 Nextcloud，再决定正式底座；独立 compose、独立 volumes、只监听本机 8780；不改底座核心，优先官方 API/应用插件 | `docs/开源底座选型-2026-10-07.md:14`、`:22` |
| Agent 只有被明确选择/召唤后才参与 | `templates/home.php`（「Agent 只有在你选择或召唤后才参与」）、`HermesAgentService.php:19-32`（要求 participant_id 必填） |
| 未做的事按 §5 的顺序 | 本会话用户给出的编号顺序 |

---

## 5. 未做的事（按用户给的顺序）

### 5.1 修 Activity 页面空白

- 现状：Docker 未运行、Nextcloud 不可达，无法复现或验证（§2.1）
- 已核实的相关位置：`appinfo/routes.php`（两条路由）、`ActivityController.php:36-46`（NoCSRFRequired 已在）、`templates/activities.php`、`js/activity-page.js`、`TaskFeedbackListener.php`（activity-page.js 被加载两次）
- 历史原因（有 diff 依据）：缺 `#[NoCSRFRequired]` 导致 412，已在 `26fd62b` 修复

### 5.2 context_snapshot + envelope 字段同一个迁移，快照不全量重复存历史

- 现状：`cognos_activity_messages` **没有** envelope 字段，也**没有** context_snapshot 字段（§2.3-A）
- FastAPI 侧可参考的现成列定义：`activity_context_snapshots(id, activity_id, run_id, call_index, activity_revision, context_json, rendered_prompt, rendered_prompt_hash, created_at)`（`web/activity_store.py:84`）
- 现有迁移文件序列到 `Version001500Date20261013090000.php`，下一个新迁移的版本号需接在 `0015` 之后

### 5.3 信封解析

要求（用户原话要点）：
- 区分三类：纯文本（合法）／解析成功／疑似 JSON 但失败（记录原文和错误）
- 要有成功率统计
- 字段包含 `pause`
- 前端处理只有 `id`/`status` 的更新

现状与可参考实现：
- Nextcloud 侧无任何解析代码（§2.6）
- FastAPI 侧的对应实现是 `web/activity_service.py:106-253` `parse_activity_reply()`，已处理：```json 围栏剥离、`\_` 修复、`\[`/`\]` 修复、`pause.text → pause.reason` 修复、`tension status active → open` 修复；formation 项允许只带 `id`+`status`（缺 `text`/`refs` 时保留字段缺省）；tension 同理；输出为 `{message, formation_updates, tension_updates, move, pause, legacy, transport_wrapped, transport_repairs, raw, raw_text}`
- 同一实现里有 reply 必须至少含 `message`/`formation_updates`/`tension_updates`/`move`/`pause` 之一的校验（`activity_service.py:247-248`）

### 5.4 四部分融合方案

- 现状：尚无（§3 末）
- Talk / Collectives 的 API 与数据结构检索：未做

---

## 6. 未核实 / 不确定

1. **PostgreSQL live schema 与行数**：Docker 未运行，`oc_cognos_*` 表本次未查询；§2.3-A 全部依据迁移文件，与 live schema 是否一致未核实
2. **Activity 页面当前是否仍空白**：未复现；本会话压缩前记录到的「已修复、可渲染」状态本次无法重现
3. **Nextcloud 应用当前状态**：是否 enabled、版本、`needsDbUpgrade`、`maintenance` 均未查询
4. **Hermes API 当前状态**：8642 不可达；`hermes-cognos` 模型是否可用、`/run/secrets/hermes_api_key` 是否存在未核实
5. **Nextcloud Activity 的 assistant 消息真实原文**：一条都没取到（§2.7）
6. **`.cognos-state/*.db` 的角色**：已核实 `cognos-app` 代码不引用它们；但它们是否仍被某个并行服务写入、以及 `notes.db` 与 `notes.db.backup.20261010_004428`（1368 行 vs 0 行）的差异原因，未核实
7. **`talk_bridge.db` 最新事件为 2026-10-07**：Talk webhook 当前是否仍配置、bot 是否仍在房间，未核实
8. **Collectives 版本号**：仅核实到「已启用」；未取得版本号
9. **`js/home.js` 之外的前端行为**：`js/notes-index.js`（51 行）、`js/board-activity.js` 等本次未逐一核对
10. **公开访问地址**：`docs/Cognos-当前交接-精简版.md` 记录 `choir-bestsellers-calls-labor.trycloudflare.com` 已失效；当前是否有可用公网地址未核实
11. **`/home/lnp/cognos-nextcloud-实际状态.md`（2026-10-13，147 行）里的断言**：例如「FastAPI 有 162 项回归测试通过」——本会话未核实，不应直接引用为事实
12. **`TaskFeedbackListener` 是否导致 activity-page.js 重复执行出问题**：已核实存在两次 `addScript` 调用，但未核实 Nextcloud 是否去重、是否造成实际影响