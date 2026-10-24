# Cognos 项目全貌

更新：2026-10-24。任何 agent（Claude Code / claude.ai / Hermes / Codex）进入本项目先读这一份。

**这是项目唯一的全局说明。** `CLAUDE.md`、`AGENTS.md`、`docs/Cognos-设计文档入口.md` 只保留各自的工作规则并指向这里；项目现状、结构、开发方式、设计文档位置有变化时，改这一份，不要在别处另写一份。

## 一句话

Cognos 是一个协助人和 AI 一起思考、完成任务、组织调度的系统。Cognos 就是整个项目；Nextcloud 只是它借用的开源底座（因为自写的完成度不够，才换到开源底座上）。

## 产品结构（用户 2026-10-21 确认）

- 最上层只有两大部分：**创立空间**、**示范空间**。
- 示范空间里有四个部分：
  - **笔记**：参考 / 仿照 Obsidian
  - **组织**：参考飞书、群聊软件 + agent
  - **项目**：参考项目管理软件、团队协作
  - **学习**：要做深，不要做成肤浅的功能列表
- Nextcloud 原有能力（仪表盘、文件、Deck、Talk、Notes…）要**融合进**这个框架，不是挤在旁边，也不是另起页面。
- Agent 是贯穿能力，进入笔记讨论和组织协作，不是独立系统。
- Obsidian 是来源层；导入 Obsidian 不算完成，材料要进入 Nextcloud 里的「笔记 → Activity」使用链。

## 代码与运行

| 项 | 位置 |
|---|---|
| 仓库 | `C:\Users\lnp\Documents\Playground\cognos`（WSL 里是 `/mnt/c/Users/lnp/Documents/Playground/cognos`） |
| 产品代码 | `deploy/nextcloud-trial/cognos-app/`（PHP 8 + Vanilla JS） |
| 本地地址 | http://127.0.0.1:8780 （主页 `/apps/cognos/home`，创立空间 `/apps/cognos/creations`） |
| 启动 | 先开 Docker Desktop，再 `deploy/nextcloud-trial/start.ps1` |
| 管理员 | `cognos-admin`，密码在 `deploy/nextcloud-trial/.secrets/admin-password`（不入库） |
| Agent 后端 | Hermes API `host.docker.internal:8642`，模型走 a6api 中转 |
| 历史原型 | `web/`（FastAPI）、`phase0/`、`phase1/`、`probe/`、`boundary/`：早期实验与证据，不是现行产品 |

## 当前进展（2026-10-24）

- master：首页两大部分改版、创立空间、Notes（含 1368 条 Obsidian 导入）、Activity + Hermes 对话、参与者路由。页面可正常打开。
- 分支 `wip/agent-rag-memory`：10/21 未完成的 agent 工具调用 + RAG + 记忆 + Deck/Talk 深度集成。`HermesAgentService.php` 写到一半被截断（不能解析，缺 17 个方法），需要补完后再合并。
- 用户最后的反馈（10/21）：UI 挤在左边、太简陋；每个部分点进去要先建空间——要删掉；要参考成熟产品重做四个部分；学习空间要做深。
- 10/24：用户把**展开**（想法、笔记、项目思考、学习的展开）定为第一个核心点。讨论和原话整理在 `docs/空间与展开/`，动手设计前先读那里的 README，里面列了已经否掉、不要再提的方案。

## 开发方式

代码结构：

```
deploy/nextcloud-trial/
├── cognos-app/          # Nextcloud 应用（产品代码）
│   ├── appinfo/         # 元数据、routes.php
│   ├── lib/Controller/  # 控制器
│   ├── lib/Service/     # 服务层（含 HermesAgentService）
│   ├── lib/Migration/   # 数据库迁移
│   ├── templates/       # PHP 模板
│   ├── js/  css/        # 前端（Vanilla JS、CSS）
└── docker-compose.yml
```

常用命令（在 `deploy/nextcloud-trial/` 下）：`docker-compose up -d` 启动，`docker-compose logs -f nextcloud` 看日志，`docker-compose restart nextcloud` 重启，`docker exec -it nextcloud-trial-nextcloud-1 bash` 进容器。改了路由、控制器或静态资源后要 `occ app:disable cognos && occ app:enable cognos`，再到 8780 上看实际页面。

代码风格：PHP 按 PSR-12 和 Nextcloud 框架约定；JS 用 ES6+、不引框架；CSS 深色主题、token 化。命名：JS camelCase，CSS kebab-case，PHP 类 PascalCase。

产品约定：模型走中国大陆中转站 `https://api.a6api.com`；所有对话界面支持 Markdown 渲染；优先深色主题和可访问性。

## 工作约定

- 有疑问直接问用户，不要自己猜着做完再交。
- 用户要看的是 8780 上真实生效的效果，不是代码讲解；改完要在实际页面上验证。
- 回复要短，先做事。

## 相关资料位置

- **所有会话里说过的想法（按主题和时间）**：`docs/思想总索引.md`，2024-09 到 2026-10-24，标了出处和确认状态，也列了被否决的提案。提新方案前先看它。
- 想法与讨论（空间、展开、基础单位）：`docs/空间与展开/`
- 设计思想来路：`docs/Cognos-总设计-v0.1-2026-09-10.md` §1（用户原话表）、`docs/Cognos-空间与认知架构-工作稿.md`、`docs/Cognos-v3-行动思想.md`
- 总设计：最后一版是 `docs/Cognos-总设计-v0.2-2026-09-11.md`（9 月，FastAPI 原型时期的候选推导，不是现行产品规格）。10 月转到 Nextcloud 底座后没有新的总设计；现行方向以本文「产品结构」和 `docs/空间与展开/` 为准。
- 详细施工记录：`docs/Cognos-v3-施工台账.md`、`docs/Cognos-Agent接续-交接-2026-10-16.md`
- 验收标准：`PRODUCT.md`、`JOURNEYS.md`、`ACCEPTANCE.md`
- Hermes 会话与记忆：WSL `~/.hermes/`（`state.db`、`memories/`）。注意 Windows 下 `C:\Users\lnp\.hermes` 是 6 月的旧安装，不是在用的那个。
