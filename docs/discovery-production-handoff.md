# Discovery-first to production-ready handoff

Repeated coding-agent work often has two different prompt jobs that should not be collapsed into one vague instruction:

```text
1. discover what is true now
2. turn that verified state into a self-contained execution prompt
```

`agent-recall-compiler` exposes those jobs as two explicit L2 operating recipes:

- `discovery-first`
- `production-ready-handoff`

They are deliberately separate. Discovery is an investigation contract. Production handoff is an execution-prompt construction contract. Neither recipe executes the implementation during its L2 construction pass.

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

## Production-ready execution handoff

A follow-up request such as:

> Write this as a prompt for another LLM without the current context and make it production-ready.

has a different job. The next agent must not depend on the current chat, private Session context, hidden reasoning, or previous-agent memory.

`production-ready-handoff` constructs a project-specific L1 execution prompt from verified current-state evidence available to Recall. When that evidence is missing, incomplete, or stale, the generated prompt must make bounded re-grounding its first step instead of filling the gap with conversational assumptions.

The generated execution prompt should carry forward the facts that materially prevent rediscovery or regression:

- exact repository anchors such as files, symbols, issues, pull requests, commits, Contracts, Runs, and artifacts;
- VERIFIED current state and already-completed work;
- disproved hypotheses, so they are not reopened without new evidence;
- task authority and semantic owner boundaries;
- scope and explicit non-goals;
- behavioral invariants and compatibility, security, or failure semantics that actually apply;
- the smallest safe implementation preference;
- positive and negative-path tests supported by the repository;
- stale-artifact, retry, or reproducibility checks when the task makes them relevant;
- exact validation commands, or explicit `UNKNOWN` obligations to discover them from repository evidence;
- falsification questions that try to disprove the proposed fix;
- observable `Done When` criteria.

"Production-ready" does not mean "broaden the task until it looks comprehensive". The recipe forbids speculative abstractions, invented commands or historical provenance, unrelated cleanup, and redesign of already-validated behavior.

Current authoritative artifacts win when they conflict with handoff text. A stale handoff must be re-planned or marked `BLOCKED`; it does not become repository truth because it is detailed.

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

After the relevant discovery state has been made available through durable task/Contract/repository evidence, use `production-ready-handoff` to construct the execution prompt for a fresh agent:

```bash
vendor/bin/agent-recall-compiler compile \
  --task PROJECT-123 \
  --description "Turn the verified discovery into a production-ready execution prompt for a fresh coding agent" \
  --file src/Example.php \
  --operating-prompt-manifest vendor/voku/agent-recall-compiler/skills/agent-recall-consumer/operating-prompts.json \
  --operating-prompt '{"id":"production-ready-handoff","arguments":{}}'
```

The second compile must not assume that a prior chat message became Recall evidence. If the relevant discovery was not persisted or otherwise supplied through an authoritative source, re-ground it.

## Relationship to `todo-card-handoff`

These recipes do not replace `todo-card-handoff`.

| Recipe | Output purpose |
| --- | --- |
| `discovery-first` | determine the current evidence-backed state and smallest safe next slice |
| `production-ready-handoff` | construct one copy-paste-ready L1 execution prompt for a fresh coding agent |
| `todo-card-handoff` | persist independently resumable work in the repository's existing durable TODO/work-card system |

A production-ready execution prompt is not automatically durable backlog. A TODO card is not automatically an executable implementation contract. Keeping those boundaries separate prevents one convenient handoff mechanism from quietly acquiring task-storage or workflow authority.

## Selection and authority

Both recipes are explicit opt-ins. Recall does not select them heuristically.

The normal L2 construction contract still applies: the receiving agent or harness produces one concrete L1 document with `Goal`, `Context`, `Constraints`, `Verification`, and `Done When`. Recall owns deterministic context and recipe semantics; it does not execute the implementation, approve scope changes, acknowledge review, or close a governed Run.
