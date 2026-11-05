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
