# Cognos working rule

Before substantial Cognos work, read `docs/Cognos-v3-施工台账.md` and `docs/Cognos-v3-行动思想.md` together.

The ledger records current construction state and evidence. The action-thought document records what the project process has changed in our understanding and therefore in how we act next. Do not collapse these into one kind of claim.

After a substantive Packet, real-model trial, architectural deletion/merge, continuity failure, or bug that changes a design assumption:

1. update the construction ledger with what actually happened and its evidence;
2. ask whether the event changes any action-level understanding;
3. if it does, revise `Cognos-v3-行动思想.md` with the process origin, current understanding, operational consequence, and what future evidence could overturn it;
4. if it does not, do not manufacture a philosophical conclusion just to make the file grow.

Engineering correctness, cognitive effect, and observability/provenance are distinct evidence classes. Passing tests must not be promoted into a claim of better reasoning; an impressive model response must not substitute for engineering verification.

Prefer revising, narrowing, merging, or deleting existing concepts when project evidence warrants it. The action-thought layer is living and revisable, not a doctrine that implementation must protect.

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
