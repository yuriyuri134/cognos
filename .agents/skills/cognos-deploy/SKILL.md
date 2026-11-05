---
name: cognos-deploy
description: 把 cognos-app 的改动放到本机 8780 上并确认生效（改版本号、加迁移、查语法、occ upgrade、重启 app、重启桥、确认只有一个桥）。改了 deploy/nextcloud-trial/cognos-app 或 tools/cognos-space-mcp 之后、说「部署了」之前用。
---

# 把改动放到 8780 上

先记住：`deploy/nextcloud-trial/cognos-app` 直接挂进 app 和 cron 容器。**工作目录是哪个分支，8780 跑的就是哪个分支**，存盘就生效，没有缓冲。踩过的坑和原因都在 `deploy/nextcloud-trial/README.md`「本机常见问题」，遇到下面没写的情况先去那里找。

## 动手前

- `git status --short`。有别人（别的会话、Codex）没提交的改动，不要覆盖、不要 stash、不要切分支；说清楚，等人定。
- 不要在工作目录切到 `info.xml` 版本更低的分支：8780 会降级，数据库比代码新。要基于更低的版本改，就叠在当前分支上开新分支，在 PR 里写明叠在哪。
- 不删数据（要清的改名，加 `.stale-<时间>`）；不点 Docker Desktop 的 Reset to factory defaults；不对卷 `down -v`。

## 改了什么，做什么

| 改动 | 要做的 |
|---|---|
| 只改了 js / css / 模板 | 浏览器强制刷新即可 |
| 改了 PHP 但没加路由 | 查语法（下面），一般不用重启 |
| 加了或改了路由（`appinfo/routes.php`） | 重启 app 容器，否则新路由 404（路由缓存在 APCu） |
| 加了迁移或改了 `info.xml` 版本号 | 先读一遍迁移，再 `occ upgrade`，再重启 app 容器 |
| 改了 `tools/cognos-space-mcp/claude_bridge.py` 或桥给 agent 开的工具清单 | 重启桥（下面） |
| 只改了 `server.py` 里已有工具的实现 | 不用重启桥：每次运行都新起一个 MCP 进程 |

版本号和迁移：每次加迁移都把 `info.xml` 往上升（看 master 上最新的，下一个就 +0.1.0 或 +0.0.1）；迁移文件名 `VersionXXYYZZDate<年月日时分秒>.php`，只加表加列，不删不改旧数据。

## 命令（Git Bash）

查语法（容器里的 php；`MSYS_NO_PATHCONV=1` 不能少，不然路径被改写）：

```bash
cd deploy/nextcloud-trial/cognos-app
for f in $(git diff --name-only HEAD -- . | grep '\.php$' | sed 's#deploy/nextcloud-trial/cognos-app/##'); do MSYS_NO_PATHCONV=1 docker exec cognos-nextcloud-trial-app-1 php -l /var/www/html/custom_apps/cognos/$f | grep -v '^No syntax'; done
for f in js/*.js; do node --check $f; done
```

升级数据库和重启：

```bash
docker exec -u www-data cognos-nextcloud-trial-app-1 php occ upgrade
docker restart cognos-nextcloud-trial-app-1
curl -s http://127.0.0.1:8780/status.php
```

`needsDbUpgrade` 要是 false；`occ app:list | grep cognos` 是新版本号。`occ upgrade` 末尾报 `oc_cognos_space_traces: unexpected column 'judgment'` 是老问题，不用管。

## 桥

- 找桥按谁在听 8643，不按进程名（它叫 `python3.13.exe`，而且按命令行找会把你自己的查询命令也算进去）：
  ```powershell
  Get-NetTCPConnection -LocalPort 8643 -State Listen | Select-Object OwningProcess
  ```
- 两个桥能同时绑上 8643，会互相踩。**起新桥前先停旧的，起完确认只有一个。** 桥正在做事（`/health` 的 `busy` 不空）时不要停，停了那件就断了。
- 起桥的命令和环境变量见 README「起桥、找桥、停桥」。照原来的开关起：原来开了命令（`/health` 里 `commands: true`）就设 `COGNOS_BRIDGE_COMMANDS=1`，原来没开就不开。不要顺手改开关；要改，在 PR 和汇报里写出来。
- 在 Claude Code 自动模式下，`powershell -ExecutionPolicy Bypass -File ...` 会被拦，照 README 的步骤直接 `Start-Process`。

## 算部署完

- 用 `cognos-live-check` 的 `check_smoke.cjs` 看主要页面能打开（桌面和 390px、没报错），再跑这次改动自己的检查。
- 汇报写：提交号、8780 上的版本号、走过的路由、有没有在登录后的浏览器里看到、桥是不是只有一个以及命令开关。
