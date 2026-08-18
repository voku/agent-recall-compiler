# Agent Recall Compiler (`voku/agent-recall-compiler`)

Deterministic context and L2 operational-prompt compiler for coding agents.

[![Build Status](https://github.com/voku/agent-recall-compiler/actions/workflows/ci.yml/badge.svg)](https://github.com/voku/agent-recall-compiler/actions)
[![License](https://img.shields.io/github/license/voku/agent-recall-compiler.svg)](LICENSE)

`agent-recall-compiler` is the **recall layer** of a governed coding-agent workflow. It turns approved task intent plus bounded repository evidence into a replayable briefing, project-specific prompt inputs, validation obligations, review artifacts, and outcome evidence.

It is deliberately not a larger system prompt and not an autonomous workflow owner.

The core idea is:

```text
Task intent
+
approved Contract
+
repository facts
+
relevant guidance
+
constraints
+
previous outcomes
        ↓
     COMPILE
        ↓
project-specific operational context
        ↓
L2 recipe → concrete L1 execution contract
        ↓
coding agent
        ↓
verification / review / lifecycle owner
```

The human or workflow provides intent and authority. Repository evidence provides facts. Recall deterministically selects and explains relevant context. A receiving agent may then construct and execute a project-specific L1 contract. Mechanical verification and lifecycle decisions remain outside Recall.

## Why this exists

Coding agents are fast enough that the bottleneck is often no longer implementation. The harder problem is making sure an agent receives the **right task, the right context, the right authority, and a way to prove the result**.

`agent-recall-compiler` therefore optimizes for five things:

1. **Relevant context, not maximum context.** Scope, targets, contracts, callers, tests, constraints, project documents, and prior outcomes are selected from bounded sources instead of dumping the repository into a prompt.
2. **Provenance, not plausible prose.** Context can explain `WHAT`, `WHY`, `HOW`, `AUTHORITY`, `USE`, and `STATE` without asking an LLM to invent rationale.
3. **Project-specific execution contracts.** Reusable L2 recipes compile current project facts into concrete L1 prompts with `Goal`, `Context`, `Constraints`, `Verification`, and `Done When`.
4. **Evidence before confidence.** Material claims distinguish `VERIFIED`, `INFERRED`, `ASSUMED`, `BLOCKED`, and `CONTRADICTED`; missing evidence stays missing instead of becoming confident text.
5. **Narrow authority boundaries.** Selection is not usefulness, context is not edit permission, review identity is not acknowledgement, and Recall does not silently acquire workflow or durable-learning authority.

See [Design principles](docs/design-principles.md) for the full model.

---

## Architecture

```text
                           ┌──────────────────────────┐
                           │ approved task / Contract │
                           └────────────┬─────────────┘
                                        │
                                        ▼
┌────────────────────┐      ┌──────────────────────────┐
│ bounded providers  │─────►│  Agent Recall Compiler   │
│ read-only evidence │      │ deterministic composition │
└────────────────────┘      └────────────┬─────────────┘
                                        │
                   ┌────────────────────┼────────────────────┐
                   ▼                    ▼                    ▼
          recall.bundle.json    selection-report.json      system.md
          facts.json            context_explain            validation-plan.md
          meta.json                                      recall-log.draft.json
                                        │
                                        ▼
                           optional L2 → L1 construction
                                        │
                                        ▼
                              receiving coding agent
                                        │
                                        ▼
                         verification / review / close-out
                         owned by the appropriate consumer
```

The compiler owns deterministic context composition, recipe resolution, rendering, provenance, and Recall-owned artifact semantics. It does **not** execute production edits, decide that an implementation succeeded, acknowledge review, close a governed Run, or automatically rewrite durable Learning.

---

## Core contracts

### Context selection is deterministic

Recall combines explicit task scope with bounded providers such as approved guidance, active constraints, registered project documents, project capabilities, and optional `voku/agent-map` evidence.

For exact targets, `agent-map` can derive primary source, contracts, direct change candidates, verification files, dependencies, type definitions, blind spots, and omissions without asking a model to rediscover the repository.

```bash
vendor/bin/agent-recall-compiler compile \
  --root infra/doc/agent-learning \
  --task "PROJECT-367" \
  --description "Reject inactive users before persistence" \
  --target "App\\Service\\UserService::save" \
  --map-index ".agent-map/php-symbols.json" \
  --map-root "$PWD" \
  --output-dir ".agent-recall/current"
```

Dependencies and type definitions are context-only by default. Being shown to the agent does not automatically widen edit scope.

### Context explains provenance and safe use

`selection-report.json` can expose deterministic context explanations using:

```text
WHAT       source, command, document, recipe, or decision
WHY        why it is relevant to this task
HOW        deterministic derivation path
AUTHORITY  what makes the source or claim authoritative
USE        what the receiving agent may use it for
STATE      verified / inferred / unknown / blocked
```

Discovery and authority are intentionally separate. For example, `agent-map` may explain **how** a source slice was selected, while the current repository source remains the authority for the code itself.

See [Context Explain](docs/context-explain.md).

### L2 compiles L1

Reusable engineering advice usually belongs at L2. The reusable part defines the method and quality bar; project-specific files, symbols, commands, architecture, risks, and invariants are resolved at compile time.

The target L1 shape is:

```text
Goal         = measurable outcome / minimum floor
Context      = exact repository anchors and known facts
Constraints  = invariants, scope boundaries, forbidden shortcuts
Verification = exact repository-supported measurement procedure
Done When    = observable stopping condition
```

`Verification` answers **how reality is measured**. `Done When` answers **which observed result is sufficient to stop**.

If Recall can prove an exact command from repository evidence, the generated contract can use it. If it cannot, the command remains `UNKNOWN`; the compiler does not turn package presence into an invented invocation.

See [Operating prompt recipes](docs/operating-prompts.md) and [Prompt primitives](docs/prompt-primitives.md).

### Evidence has explicit states

Generated briefings require material conclusions to distinguish:

```text
VERIFIED
INFERRED
ASSUMED
BLOCKED
CONTRADICTED
```

Model confidence, reviewer consensus, previous rationale, prompt construction, and unexecuted commands are not verification.

### Selection is not usefulness

A selected rule only proves that it entered the compiled briefing. It does not prove model attention, application, or benefit.

Outcome evidence is recorded separately as `applied` plus task-local outcomes such as `helpful`, `irrelevant`, `harmful`, `not_used`, or `unknown`. Those events are evidence for later Learning decisions; Recall does not automatically promote, weaken, retire, or rewrite guidance from counts alone.

See [Guidance event history](docs/guidance-events.md).

### Review artifacts are evidence, not approval

Recall can generate deterministic blind-spot reports and review prompts:

```bash
vendor/bin/agent-recall-compiler review blindspots PROJECT-367 \
  --output-dir ".agent-recall/current"

vendor/bin/agent-recall-compiler review code PROJECT-367 \
  --output-dir ".agent-recall/current"
```

The blind-spot path is repo-first and falsification-oriented: material concerns should identify evidence, epistemic status, a failure chain, the earliest observable signal, and the smallest discriminating probe. A clean/no-change result is valid; review does not have a finding quota.

Lifecycle hosts can use the typed PHP API to read and identify the exact persisted review artifact. The SHA-256 identity proves **which report** was read. It does not prove that a lifecycle owner acknowledged or accepted that report.

See [Public PHP API](docs/public-api.md).

---

## Installation

```bash
composer require --dev voku/agent-recall-compiler
```

The package requires PHP `^8.3` and exposes:

```text
vendor/bin/agent-recall-compiler
```

---

## Quick start

### Standalone compile

```bash
vendor/bin/agent-recall-compiler compile \
  --root infra/doc/agent-learning \
  --task "PROJECT-367" \
  --description "Implement the new region-aware menu navigation" \
  --file "src/Navigation/MenuEntry.php" \
  --file "tests/Navigation/MenuEntryTest.php" \
  --output-dir ".agent-recall/current"
```

Read at minimum:

```text
.agent-recall/current/system.md
.agent-recall/current/validation-plan.md
```

Before close-out, complete the generated outcome draft and record the result:

```bash
vendor/bin/agent-recall-compiler log-outcome \
  --root infra/doc/agent-learning \
  --draft ".agent-recall/current/recall-log.draft.json" \
  --by "<agent-or-human>" \
  --commit "<commit-or-working-tree>"
```

### Governed use through `agent-loop`

In a governed Run, Recall consumes a small envelope that binds one `run_id` to one exact approved durable Contract revision and SHA-256 digest. The durable Contract remains the task-policy owner. Recall validates that input and compiles context; `voku/agent-loop` owns orchestration, execution gating, review acknowledgement, and close-out.

See [Operating prompt recipes: governed input](docs/operating-prompts.md#governed-input).

---

## Generated artifacts

| Artifact | Purpose |
| --- | --- |
| `recall.bundle.json` | Canonical replayable task snapshot with selected learning, provider facts, and source digests. |
| `facts.json` | Compact structured facts for consumers such as `agent-loop`. |
| `selection-report.json` | Deterministic selection/exclusion reasoning, effective scope, and context provenance. |
| `system.md` | Human/model-readable Recall briefing and selected prompt construction material. |
| `validation-plan.md` | Required validation commands, hard-constraint identifiers, and provenance. |
| `meta.json` | Technical metadata and artifact identities. |
| `recall-log.draft.json` | Editable close-out draft for guidance and operating-prompt outcomes. |

Compilation fails closed when selected input cannot form a coherent trusted instruction set. Empty guidance is also valid; Recall does not invent synthetic `none` guidance merely to make the artifacts look populated.

---

## Project documents and constraints

Recall can include Git-tracked Skills and ADRs through a bounded document manifest. Documents are selected by explicit path-scope overlap, tag overlap, or project-wide scope; the compiler does not scan an arbitrary documentation tree and ask an LLM what looks useful.

Active hard constraints are loaded from configured manifests and bring their own exact validation commands. Conflicting, inactive, superseded, malformed, or unverifiable constraints fail compilation rather than silently weakening the task contract.

For provider, precedence, and artifact details, see [Recall provider architecture](docs/recall-provider-architecture.md).

---

## Documentation

Start with the [documentation index](docs/README.md).

Important design and integration references:

- [Design principles](docs/design-principles.md)
- [Operating prompt recipes](docs/operating-prompts.md)
- [Prompt primitives](docs/prompt-primitives.md)
- [Context Explain](docs/context-explain.md)
- [Recall provider architecture](docs/recall-provider-architecture.md)
- [Guidance event history](docs/guidance-events.md)
- [Embedding Recall](docs/embedding.md)
- [Public PHP API](docs/public-api.md)
- [Dependency readiness](docs/dependency-readiness.md)

Bundled package skills live under `skills/`:

- [`agent-recall-consumer`](skills/agent-recall-consumer/SKILL.md) for consumers compiling Recall context and recording outcomes.
- [`agent-recall-compiler-maintainer`](skills/agent-recall-compiler-maintainer/SKILL.md) for changes to this package.

---

## Development and testing

Repository-supported validation is defined in `composer.json`:

```bash
composer ci
```

Equivalent focused commands are:

```bash
composer test
composer phpstan
```

The CI script runs strict Composer validation, PHPUnit, and PHPStan.

---

## Design boundary in one sentence

> Recall compiles bounded, explainable, replayable context and prompt semantics; it does not become the authority that executes, approves, or permanently learns from the work.

## License

MIT. See [LICENSE](LICENSE).
