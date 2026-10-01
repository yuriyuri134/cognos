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
- Do not use subagents merely to restate or validate conclusions that can be checked directly from code, tests, runtime evidence, or the continuity index.
