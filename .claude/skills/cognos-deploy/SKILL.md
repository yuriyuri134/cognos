---
name: cognos-deploy
description: 把 Cognos Nextcloud app 的改动放到本机 8780 上生效并核对：查语法、升级数据库、重启、确认只有一个桥、登录后看页面。改了 cognos-app 或桥、切了分支、合了 PR 之后用。
---

# 把改动放到 8780 上并核对

适用：改了 `deploy/nextcloud-trial/cognos-app/` 或 `tools/cognos-space-mcp/`，切了分支，或者合了 PR 以后。
本机常见问题的原因和来历在 `deploy/nextcloud-trial/README.md` 的「本机常见问题」，这里只写步骤。

## 先知道的三件事

- `cognos-app` 目录直接挂进 app 和 cron 容器。本机工作目录切到哪个分支，8780 跑的就是哪个分支的代码。所以切分支、stash、合并都会立刻改变 8780。
- 不删数据。要清掉的东西一律改名（加 `.stale-<时间>`）。不对卷用 `down -v`，不跑全局 prune，Docker Desktop 报错时不点 Reset to factory defaults。
- 别的会话（Codex、Hermes、另一个 Claude Code）可能也在用这个目录。动手前先跑 `git status --short --untracked-files=all`，不是自己的改动不碰。

## 步骤

1. **查语法**（在本机仓库根目录）
   ```bash
   docker exec cognos-nextcloud-trial-app-1 sh -c 'cd /var/www/html/custom_apps/cognos && find lib appinfo -name "*.php" -print0 | xargs -0 -n1 php -l | grep -v "No syntax errors"'
   for f in deploy/nextcloud-trial/cognos-app/js/*.js; do node --check "$f" || echo "BAD $f"; done
   python -m unittest discover -s tools/cognos-space-mcp -p "test_*.py"
   git diff --check
   ```
   没有输出（除了测试的 OK）才往下走。

2. **看版本和迁移有没有变**
   ```bash
   git diff <之前的提交> -- deploy/nextcloud-trial/cognos-app/appinfo/info.xml deploy/nextcloud-trial/cognos-app/lib/Migration/
   ```
   - 有新迁移：先读一遍迁移文件，确认没有删表、删列、清数据，再跑第 3 步。
   - 版本降了（切回旧分支）：不跑 `occ upgrade`，只跑 `app:disable` / `app:enable`。新版本建的表和列留在库里，不删。

3. **升级、重新启用**（版本号或迁移变了才需要 `upgrade`）
   ```bash
   docker exec -u www-data cognos-nextcloud-trial-app-1 php occ upgrade
   docker exec -u www-data cognos-nextcloud-trial-app-1 php occ app:disable cognos
   docker exec -u www-data cognos-nextcloud-trial-app-1 php occ app:enable cognos
   ```
   只加了路由、版本号没变：`app:disable` / `app:enable` 清不掉路由缓存，要 `docker restart cognos-nextcloud-trial-app-1`。
   用 `occ config:app:set` 改了配置：同样要重启 app 容器，网页进程才会读到新值。

4. **确认站点起来了**
   ```bash
   curl -s http://127.0.0.1:8780/status.php
   docker exec -u www-data cognos-nextcloud-trial-app-1 php occ app:list | grep -A1 cognos
   ```
   `needsDbUpgrade` 必须是 `false`，cognos 的版本要和 `info.xml` 一致。503 多半是第 3 步没做。

5. **桥**（改了 `tools/cognos-space-mcp/` 才要重启）
   - 找桥：`Get-CimInstance Win32_Process | Where-Object { $_.CommandLine -like '*claude_bridge*' } | Select-Object ProcessId,CreationDate`。进程名是 `python3.13.exe`，按名字找不到。
   - 两个桥能同时占着 8643 而不报错，会互相踩。起新桥前先 `taskkill /T /F /PID <pid>` 停旧的（正在做的那件会断，先看 `/health` 里有没有在忙的），起完再查一次，确认只剩一个。
   - 起桥照 README「起桥、找桥、停桥」那条的 PowerShell 命令。环境变量要和原来一样，尤其是 `COGNOS_BRIDGE_COMMANDS`：原来是 1 就还设 1。要改它，先在交付里写明为什么改。
   - `curl http://127.0.0.1:8643/health`，看 `pending`（没发出去的结果）是不是 0。

6. **登录后看页面**
   - 用 `deploy/nextcloud-trial/browser-check.cjs` 写检查，不要再复制一份登录和截图的代码。用法见文件开头。
   - 至少打开改动涉及的那一页，截桌面图和 390px 图，自己看一眼截图，确认没有未捕获异常。
   - 只用 `cognos-admin` 账号（密码在本机 `.secrets/`，不打印、不写进报告和提交）。不用小林自己的账号。

## 交付时报告

- 本机 HEAD 提交号、8780 上的 cognos 版本、`needsDbUpgrade`。
- 跑了哪几步，哪一步没跑、为什么。
- 页面看过哪几页，截图在 `evidence/` 的哪个文件。
- 桥有没有重启、现在几个、`COGNOS_BRIDGE_COMMANDS` 是什么。
