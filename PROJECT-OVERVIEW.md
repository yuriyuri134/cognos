# Cognos 项目全貌

更新：2026-10-24。任何 agent（Claude Code / claude.ai / Hermes / Codex）进入本项目先读这一份，再按需读 `CLAUDE.md`、`AGENTS.md`。

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

## 工作约定

- 有疑问直接问用户，不要自己猜着做完再交。
- 用户要看的是 8780 上真实生效的效果，不是代码讲解；改完要在实际页面上验证。
- 回复要短，先做事。

## 相关资料位置

- 详细施工记录：`docs/Cognos-v3-施工台账.md`、`docs/Cognos-Agent接续-交接-2026-10-16.md`
- 验收标准：`PRODUCT.md`、`JOURNEYS.md`、`ACCEPTANCE.md`
- Hermes 会话与记忆：WSL `~/.hermes/`（`state.db`、`memories/`）。注意 Windows 下 `C:\Users\lnp\.hermes` 是 6 月的旧安装，不是在用的那个。
