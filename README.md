# Cognos

〔原 10/23〕「想创造一个东西，让这个东西协助我和ai，协助我们思考，完成任务，组织调度」。〔原 2026-06-18，10/24 再说〕「我想让ai学会真正的思考，或者像我一样能够思考」。

现在的 Cognos 是一个跑在 Nextcloud 上的应用：最上层是**创立空间**和**示范空间**（笔记、组织、项目、学习）；agent（Hermes、本机 Claude Code）被叫进创立空间里的一片片空间，在那里留下尝试、押着前提、被顶回来、接着走。

## 先读什么

1. [`PROJECT-OVERVIEW.md`](PROJECT-OVERVIEW.md)：唯一的全局说明。先读〇到二节（动手前先问自己的六问、项目为了什么、AI 在这里反复犯的错），再看产品结构、代码与运行、当前进展。
2. [`docs/README.md`](docs/README.md)：全部资料的地图，每份是什么、在哪，给 AI 的阅读顺序，哪些故意不在仓库里。
3. [`docs/现状-2026-10-28.md`](docs/现状-2026-10-28.md)：现在走到哪、卡在哪（快照）。

agent 的工作规则在 [`CLAUDE.md`](CLAUDE.md)、[`AGENTS.md`](AGENTS.md)，都先指回 PROJECT-OVERVIEW。

## 真实代码在哪

| 位置 | 是什么 |
|---|---|
| [`deploy/nextcloud-trial/cognos-app/`](deploy/nextcloud-trial/cognos-app/) | **现行产品**：Nextcloud 应用（PHP 8 + Vanilla JS）。控制器在 `lib/Controller/`，服务在 `lib/Service/`，迁移在 `lib/Migration/`，Cognos 自己那片空间的种子在 `seeds/` |
| [`deploy/nextcloud-trial/`](deploy/nextcloud-trial/) | 本机底座：compose、启动脚本（`start.ps1`）、验收脚本 |
| [`tools/cognos-space-mcp/`](tools/cognos-space-mcp/) | 让外面的 agent 走进空间的 MCP，和把本机 Claude Code 叫进空间的桥 |
| [`experiments/reading-engine/`](experiments/reading-engine/) | 10/24 读法引擎试验，一次尝试的记录，不是方向 |

本地运行：先开 Docker Desktop，再跑 `deploy/nextcloud-trial/start.ps1`，打开 http://127.0.0.1:8780 （主页 `/apps/cognos/home`，创立空间 `/apps/cognos/creations`）。改了路由、控制器或静态资源以后要重新启用应用。细节见 PROJECT-OVERVIEW 的「代码与运行」「开发方式」。

## 旧原型（不是现行产品）

10 月初换到 Nextcloud 底座之前的实验都还留着，当来路和证据看，不要从这里起步，也不要把它们的假设当定案：

- [`web/`](web/)：9 月的 FastAPI 雏形，在一个持续发生场里和 Codex 沟通，原文写回 Obsidian Vault。
- [`phase0/`](phase0/)：经 Codex SDK 创建并恢复同一条 thread 的探针；[`phase1/`](phase1/) 是来源回放探针；[`probe/`](probe/) 是 8 月的载体对比实验装置；[`boundary/`](boundary/) 是 8 月起和外部环境（Vault、DSH）之间的同步边界。
- 根目录的 `models.py`、`database.py`、`test_phase1.py`：早期固定 schema 实验。新代码不要导入它们，也不要因为它们存在，就把这些假设当成定案：基础实体只有五种预列对象；对象必须先有固定 `type` 和全局 `subject_level`；关系必然是预列类型的二元边；Movement 必须落进预列事件类型；当时的 SQLite schema 能穷尽以后的世界映射。
- [`work/`](work/)：9 到 10 月试验的输出（JSON、截图）。
- `DSH_SESSION_ENTRY_2026-08-16.md`：8/16 的会话交接。

另外，`PRODUCT.md`、`JOURNEYS.md`、`ACCEPTANCE.md`、`MODEL.md` 是 10/07 选定 Nextcloud 底座时写的第一版产品目标、核心旅程、验收证据和系统模型，属于现行底座，但写在空间和尝试这条线之前。
