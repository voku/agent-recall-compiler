# Discovery-first to durable work package to execution dispatch

Repeated coding-agent work can involve three different prompt/artifact jobs that should not be collapsed into one giant handoff:

```text
1. discover what is true now
2. persist independently resumable work through the existing durable task owner
3. dispatch one bounded current execution slice from current authority and environment evidence
```

`agent-recall-compiler` exposes those jobs through explicit L2 operating recipes:

- `discovery-first`
- `todo-card-handoff`
- `production-ready-handoff`
- `execution-dispatch`

The first recipe investigates. `todo-card-handoff` constructs durable work-package/card guidance. `production-ready-handoff` constructs one bounded self-contained execution contract when durable backlog is not needed. `execution-dispatch` constructs the short current execution prompt for a pre-existing durable work package. None of the L2 construction passes executes implementation or grants workflow authority.

## Why discovery comes first

A request such as "inspect this PR and find the smallest safe follow-up" is not yet an implementation specification. Repository state may already have moved, a review finding may already be fixed, an assumed blocker may be stale, or the smallest correct action may be no code change at all.

`discovery-first` therefore constructs a project-specific L1 prompt that requires the next agent to re-ground the task against current evidence before proposing or implementing a change.

The generated prompt is expected to inspect only evidence relevant to the task, such as:

- current branch, diff, or target state;
- issue, pull-request, review, or durable task history already present in Recall context;
- affected files, symbols, callers, tests, and runtime boundaries;
- dependency and toolchain state when it can change the conclusion;
- repository-supported validation;
- work already completed or hypotheses already disproved.

Material conclusions keep their epistemic state:

```text
VERIFIED
INFERRED
UNKNOWN
BLOCKED
CONTRADICTED
```

A disproved hypothesis is useful evidence. `NO_CHANGE` is a valid discovery outcome. Missing or conflicting authority is `BLOCKED`, not an invitation to invent architecture, commands, or scope.

The useful discovery result is the smallest evidence-backed next slice, its semantic owner boundary, the exact probe or verification needed before editing, and the behavior that must remain unchanged.

## Choose the artifact by resumability, not prompt length

Do not use an arbitrary token or character threshold to decide whether work belongs in a prompt or durable task storage. Use the semantic boundary:

```text
one bounded execution contract
    -> production-ready-handoff

independently resumable multi-slice / cross-repository / release / follow-up work
    -> todo-card-handoff through the existing durable task owner

pre-existing durable work package + current approved execution authority
    -> execution-dispatch
```

A long prompt is not automatically wrong, and a short task card is not automatically durable. The deciding question is whether independent work must survive the current execution context and be resumed without relying on chat history.

## Durable work packages

`todo-card-handoff` is the Recall-side semantic path for portable durable work-package candidates. It must use the repository's existing durable task/card owner and format. Recall does not create a second Kanban system and does not treat a generated candidate as approved Contract or Run authority.

Durable work should carry the information needed to resume safely:

- intended outcome and why it matters;
- VERIFIED current state and already-completed work;
- exact issue, pull request, commit, file, symbol, Contract, Run, or artifact anchors;
- bounded milestones/slices and dependencies;
- acceptance evidence and validation obligations;
- blockers and UNKNOWNs;
- scope and non-goals;
- observable final Done When.

Keep the durable representation portable and environment-agnostic where practical. Reference authoritative artifacts rather than copying complete histories. Do not persist transient host availability, giant environment dumps, secrets, tokens, credentials, or guesses about a future VM.

If Recall cannot establish the existing durable task/card owner, return `BLOCKED`. Do not invent storage merely to make a handoff look complete.

## Production-ready execution handoff

A follow-up request such as:

> Write this as a prompt for another LLM without the current context and make it production-ready.

may still be appropriate when the requested work is one bounded execution contract. The next agent must not depend on the current chat, private Session context, hidden reasoning, or previous-agent memory.

`production-ready-handoff` constructs that bounded project-specific L1 execution prompt from verified current-state evidence available to Recall. When evidence is missing, incomplete, or stale, the generated prompt must make bounded re-grounding its first step instead of filling gaps with conversational assumptions.

Before calling the handoff production-ready, identify known hard prerequisites with their owner, verification probe, current evidence state, whether the delegated worker can satisfy them, and whether they are required before execution. A known required prerequisite that is missing outside delegated authority is `NOT_READY_TO_DELEGATE`.

The recipe must not grow into durable backlog. If the requested handoff contains independently resumable milestones, cross-repository or release sequencing, or follow-up work that should survive the current execution context, it returns `WORK_PACKAGE_REQUIRED` when an existing durable task owner is known and points to `todo-card-handoff`. It does not persist cards implicitly. If durable ownership is required but unavailable or conflicting, it returns `BLOCKED`.

For a bounded execution contract, the generated prompt may carry only the milestones that belong to that contract together with the relevant current anchors, owner boundaries, tests, validation, failure semantics, falsification questions, and observable Done When criteria.

"Production-ready" does not mean "broaden the task until it looks comprehensive". The recipe forbids speculative abstractions, invented commands or historical provenance, unrelated cleanup, and redesign of already-validated behavior.

Current authoritative artifacts win when they conflict with handoff text. A stale handoff must be re-planned or marked `BLOCKED`; it does not become repository truth because it is detailed.

## Dispatch-time execution prompt

`execution-dispatch` assumes durable work already exists. It produces a short L1 prompt for one current bounded execution slice.

The dispatch contract requires:

- exact lineage to the selected durable task/work-package revision;
- current approved Contract/Run/stage authority;
- current repository anchors;
- only the current slice, dependencies, required artifacts, validation, and failure semantics;
- bounded current environment evidence only when correct execution genuinely depends on it.

Environment evidence is not task policy. Accept only bounded capability facts supplied through an explicit owner boundary. Do not consume arbitrary environment dumps, secrets, tokens, credentials, or host-selected scope. If required environment evidence is missing, stale, conflicting, or proves a required capability unavailable outside delegated authority, the dispatch is `NOT_READY_TO_DELEGATE` or `BLOCKED` rather than guessed into existence.

Repository, Contract/Run/stage, and current bounded environment evidence beat stale dispatch text. Regenerate the dispatch prompt when those facts drift.

The concrete typed runtime-observation boundary is intentionally owned outside Recall prompt semantics. For the current stack, `voku/agent-loop#282` owns the Loop-side integration needed to accept bounded Runner observations without moving prompt authority into Runner.

## Sequential use

Use `discovery-first` when the task still needs a current-state decision:

```bash
vendor/bin/agent-recall-compiler compile \
  --task PROJECT-123 \
  --description "Inspect the current implementation and identify the smallest safe follow-up" \
  --file src/Example.php \
  --operating-prompt-manifest vendor/voku/agent-recall-compiler/skills/agent-recall-consumer/operating-prompts.json \
  --operating-prompt '{"id":"discovery-first","arguments":{}}'
```

For independently resumable work, explicitly select `todo-card-handoff` and let the repository's existing task owner persist or update the candidate. Do not treat the generated candidate as approval.

For one bounded handoff without durable backlog semantics, use `production-ready-handoff`:

```bash
vendor/bin/agent-recall-compiler compile \
  --task PROJECT-123 \
  --description "Turn the verified discovery into one bounded production-ready execution prompt" \
  --file src/Example.php \
  --operating-prompt-manifest vendor/voku/agent-recall-compiler/skills/agent-recall-consumer/operating-prompts.json \
  --operating-prompt '{"id":"production-ready-handoff","arguments":{}}'
```

When a durable work package already exists and current execution authority/environment evidence are available, explicitly select `execution-dispatch`:

```bash
vendor/bin/agent-recall-compiler compile \
  --task PROJECT-123 \
  --description "Dispatch the current authorized slice from the durable work package" \
  --file src/Example.php \
  --operating-prompt-manifest vendor/voku/agent-recall-compiler/skills/agent-recall-consumer/operating-prompts.json \
  --operating-prompt '{"id":"execution-dispatch","arguments":{}}'
```

No compile may assume that prior chat became Recall evidence. If the relevant durable revision, authority, repository state, or required bounded environment evidence is unavailable, re-ground or fail closed.

## Artifact and authority matrix

| Recipe | Output purpose | What it must not become |
| --- | --- | --- |
| `discovery-first` | determine current evidence-backed state and smallest safe next slice | implementation or approval |
| `todo-card-handoff` | construct portable durable work-package/card candidates through the existing task owner | Contract approval, Run authority, environment snapshot |
| `production-ready-handoff` | construct one self-contained bounded execution contract | giant resumable backlog |
| `execution-dispatch` | construct one current environment-grounded execution prompt from an existing work package | durable storage, scheduler, host policy, workflow authority |

A durable card is not an executable approval. A generated prompt is not workflow authority. Runner/runtime capability observations are evidence, not permission to widen scope.

## Selection and authority

All recipes are explicit opt-ins. Recall does not heuristically choose to create backlog or silently switch a production handoff into dispatch.

The normal L2 construction contract still applies: the receiving agent or harness produces one concrete L1 document with `Goal`, `Context`, `Constraints`, `Verification`, and `Done When`. Recall owns deterministic context and recipe semantics; it does not execute the implementation, persist task cards by itself, approve scope changes, acknowledge review, select a host, or close a governed Run.
