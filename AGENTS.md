# Cognos working rule

Project background, implementation, code locations, and development commands are in `PROJECT-OVERVIEW.md`. Historical discussions and experience are in `docs/`; consult them as relevant to the task.

Engineering verification, reasoning quality, and provenance are different kinds of evidence. Passing tests does not establish better reasoning. Report actual execution results and distinguish unverified inferences from confirmed facts.

## Agent/request pacing

Use the main thread as the default worker. Spawn a subagent only when an independent audit or genuinely separable investigation is likely to change the next action.

- Keep at most one subagent active at a time unless the user explicitly asks for broader parallelism.
- Batch related questions into one subagent task instead of sending several short prompts in a burst.
- Reuse a healthy subagent for follow-up work instead of creating replacements.
- If a subagent hits capacity, stream-disconnects, or fails to confirm the prompt, do not immediately respawn or retry several times. Continue useful main-thread work and retry later only if the independent review is still needed.
## Shared workspace and visible delivery

All Hermes/Codex sessions working on Cognos use this single worktree:
`/mnt/c/Users/lnp/Documents/Playground/cognos`.
Do not create another Cognos clone, worktree, or parallel copy unless the user explicitly asks for an isolated experiment.

Before editing:

1. Run `git status --short --untracked-files=all`.
2. Read the target files and preserve unrelated dirty changes.
3. Check whether another session is currently changing the same files; if ownership is unclear, do not overwrite them.

After a substantive change:

1. Run the narrow validation for the target runtime.
2. Run `git diff --check`.
3. Stage only the files belonging to this change and commit them with a descriptive message. Never commit secrets, credentials, database files, tunnel state, or unrelated worktree changes.
4. Reload the actual target runtime. For the Nextcloud product this means the Docker app/container and `occ app:disable cognos && occ app:enable cognos` when route/controller/assets changed.
5. Verify the exact user-visible route on the current runtime. A source edit or Git commit alone is not a deployed feature.
6. Report the commit ID, runtime URL/route, and whether authenticated browser visibility was verified.

If the worktree already contains broad uncommitted changes from another session, do not make a catch-all commit. Commit only a clearly owned slice, or leave the slice uncommitted and state the exact blocker.

The product runtime is the Nextcloud deployment under `deploy/nextcloud-trial/`; the FastAPI `web/` tree is prototype/evidence work unless the user explicitly requests it. Quick Tunnel URLs are temporary and must never be used as proof that the current local commit is publicly visible.

## 范围和停下（2026-11-05 小林同意加的）

交代给你的一块，就做那一块：

- **一块一个 PR，从最新的 master 开。** 步骤和说明格式见 `.claude/skills/cognos-pr/SKILL.md`。
- **做完就停。** 交代的那几件做完、验证过、PR 开好，就交回来，不接着往外扩。还想做的写在 PR 的「范围外看到的」里，等小林决定。
- **可以站在外面看，也可以质疑架构。** 发现结构有问题，就写下来：哪里不对、想怎么改、会动哪些地方、要多久。小林同意了再动。改架构单独开 PR，不和交代的那块混在一起。
- **验证和改动的分量相当。** 改了什么就验证什么。并发交错、断电、备份演练这类重的验证，交代里没要、改动也不碰那一块时，不做，列进「范围外看到的」。
- **大约两小时还没做完，先交一次。** 把已经做完的部分提交、写清楚还差什么，再继续。额度或时间随时可能用完，没提交的改动留在共用的工作目录里，会直接带进 8780。
- 改了桥的启动方式或环境变量（比如 `COGNOS_BRIDGE_COMMANDS`），在交付里写明改了什么、为什么。

## 反复要做的固定步骤

这些写在 `.claude/skills/` 里。Claude Code 会自己加载，Codex 和其他 agent 照着读：

- 把改动放到 8780 并核对（查语法、升级、重启、桥只有一个、看页面）：`.claude/skills/cognos-deploy/SKILL.md`
- 开 PR、写说明、按顺序合：`.claude/skills/cognos-pr/SKILL.md`
- 登录后的浏览器检查：用 `deploy/nextcloud-trial/browser-check.cjs`，不要再复制一份登录和截图的代码。
- 本机踩过的坑（Docker 起不来、火绒、桥、503、路由 404）：`deploy/nextcloud-trial/README.md`「本机常见问题」。
