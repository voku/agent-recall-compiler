# Agent Recall Compiler (`voku/agent-recall-compiler`)

Deterministic context and L2 operational-prompt compiler for coding agents.

[![Build Status](https://github.com/voku/agent-recall-compiler/actions/workflows/ci.yml/badge.svg)](https://github.com/voku/agent-recall-compiler/actions)
[![Latest Stable Version](https://poser.pugx.org/voku/agent-recall-compiler/v/stable)](https://packagist.org/packages/voku/agent-recall-compiler)
[![Total Downloads](https://poser.pugx.org/voku/agent-recall-compiler/downloads)](https://packagist.org/packages/voku/agent-recall-compiler)
[![Monthly Downloads](https://poser.pugx.org/voku/agent-recall-compiler/d/monthly)](https://packagist.org/packages/voku/agent-recall-compiler)
[![License](https://poser.pugx.org/voku/agent-recall-compiler/license)](https://packagist.org/packages/voku/agent-recall-compiler)
[![PHP Version Require](https://poser.pugx.org/voku/agent-recall-compiler/require/php)](https://packagist.org/packages/voku/agent-recall-compiler)
[![GitHub Stars](https://img.shields.io/github/stars/voku/agent-recall-compiler?style=flat-square)](https://github.com/voku/agent-recall-compiler/stargazers)
[![GitHub Forks](https://img.shields.io/github/forks/voku/agent-recall-compiler?style=flat-square)](https://github.com/voku/agent-recall-compiler/network/members)

`agent-recall-compiler` is the **recall layer** of a governed coding-agent workflow. It turns task intent plus bounded repository evidence into a replayable briefing, project-specific prompt inputs, validation obligations, review artifacts, and outcome evidence. In governed runs, the task input is a `governed_recall_input` envelope bound to one exact approved Contract revision and digest; standalone compilation can use inline or JSON task input without pretending that it is a governed Run.

It is deliberately not a larger system prompt and not an autonomous workflow owner.

The core idea is:

```text
Task intent
+
optional governed Contract binding
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
+ selected prompt recipes
        ↓
receiving agent / harness
        ↓
optional L2 → concrete L1 construction
        ↓
coding agent
        ↓
verification / review / lifecycle owner
```

The human or workflow provides intent and authority. Repository evidence provides facts. Recall deterministically selects and explains relevant context and renders selected prompt semantics. When an L2 recipe is selected, the receiving agent or harness constructs the concrete project-specific L1 contract. Mechanical verification and lifecycle decisions remain outside Recall.

## Why this exists

Coding agents are fast enough that the bottleneck is often no longer implementation. The harder problem is making sure an agent receives the **right task, the right context, the right authority, and a way to prove the result**.

`agent-recall-compiler` therefore optimizes for five things:

1. **Relevant context, not maximum context.** Scope, targets, contracts, callers, tests, constraints, project documents, and prior outcomes are selected from bounded sources instead of dumping the repository into a prompt.
2. **Provenance, not plausible prose.** Context can explain `WHAT`, `WHY`, `HOW`, `AUTHORITY`, `USE`, and `STATE` without asking an LLM to invent rationale.
3. **Project-specific execution contracts.** Recall validates, grounds, and renders selected L2 recipes beside current project facts; the receiving agent or harness uses that material to construct a concrete L1 prompt with `Goal`, `Context`, `Constraints`, `Verification`, and `Done When`.
4. **Evidence before confidence.** Material claims distinguish `VERIFIED`, `INFERRED`, `ASSUMED`, `BLOCKED`, and `CONTRADICTED`; missing evidence stays missing instead of becoming confident text.
5. **Narrow authority boundaries.** Selection is not usefulness, context is not edit permission, review identity is not acknowledgement, and Recall does not silently acquire workflow or durable-Learning policy authority.

See [Design principles](docs/design-principles.md) for the full model.

---

## Architecture

```text
                    ┌────────────────────────────────────┐
                    │ standalone task / governed Contract │
                    │ envelope                            │
                    └────────────────┬───────────────────┘
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
          facts.json            (`context_explain`)        validation-plan.md
          meta.json             compilation-receipt.json  recall-log.draft.json
                                        │
                                        ▼
                           optional L2 → L1 construction
                           by receiving agent / harness
                                        │
                                        ▼
                              receiving coding agent
                                        │
                                        ▼
                         verification / review / close-out
                         owned by the appropriate consumer
```

The diagram shows the **core successful compile path**, not every conditional artifact. `context_explain` is a field inside `selection-report.json` and is also rendered into `system.md`; it is not a separate file. Conditional verification and feedback artifacts are listed under [Generated artifacts](#generated-artifacts).

The compiler owns deterministic context composition, recipe resolution, rendering, provenance, Recall-owned artifact semantics, and immutable Recall outcome-event append semantics. It does **not** execute production edits, decide that an implementation succeeded, acknowledge review, close a governed Run, or automatically promote, weaken, retire, or rewrite durable Learning policy.

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

### Recall grounds L2; the consumer constructs L1

Reusable engineering advice usually belongs at L2. The reusable part defines the method and quality bar; project-specific files, symbols, commands, architecture, risks, and invariants are resolved into Recall context at compile time.

When an L2 recipe is selected, `system.md` tells the receiving agent or harness to construct exactly these five sections:

```text
Goal         = measurable outcome / minimum floor
Context      = exact repository anchors and known facts
Constraints  = invariants, scope boundaries, forbidden shortcuts
Verification = exact repository-supported measurement procedure
Done When    = observable stopping condition
```

`Verification` answers **how reality is measured**. `Done When` answers **which observed result is sufficient to stop**.

Recall does not execute that L2 construction pass itself. If Recall can prove an exact command from repository evidence, the construction material can name it. If it cannot, the command remains `UNKNOWN`; package presence is not converted into an invented invocation.

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

Outcome evidence is recorded separately as `applied` plus task-local outcomes such as `helpful`, `irrelevant`, `harmful`, `not_used`, or `unknown`. Recall can append those immutable selection/outcome events to the Learning history, but those events are evidence for later Learning decisions; Recall does not automatically promote, weaken, retire, or rewrite guidance from counts alone.

See [Guidance event history](docs/guidance-events.md).

### LearningNote precedents are context, not guidance

`voku/agent-learning` owns durable `LearningNote` records and their lifecycle. Recall reads the Learning-owned projection and may compile an active note into a `learning_precedent` fact when its scope and evidence are relevant to the current task.

That fact is deliberately low-authority precedent, not an instruction: `learning_precedent` sits below `project_skill` and above legacy `repository_memory` in explicit fact-conflict precedence. It never widens task scope, satisfies validation, approves a lifecycle transition, or becomes durable guidance merely because Recall selected it.

The compiled `learning_precedent` is derived and regenerable. Its provenance remains bound to the exact Learning projection used by that compilation; the `LearningNote` itself remains Learning-owned durable truth. When active guidance carries the same explicit `pattern_key`, Recall preserves lineage but suppresses duplicate precedent prose rather than inventing semantic equivalence from similar wording.

See [LearningNote precedents](docs/learning-note-precedents.md).

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

The generated guidance rows start as `applied=false`, `outcome=unknown`, `comment=null` placeholders. An untouched placeholder is not accepted as feedback: judge it with evidence, or remove rows that cannot be judged and set `guidance_outcomes_withheld_reason` explicitly.

### Governed use through `agent-loop`

In a governed Run, Recall consumes a small envelope that binds one `run_id` to one exact approved durable Contract revision and SHA-256 digest. The durable Contract remains the task-policy owner. Recall validates that input and compiles context; `voku/agent-loop` owns orchestration, execution gating, review acknowledgement, and close-out.

See [Operating prompt recipes: governed input](docs/operating-prompts.md#governed-input).

### Shipped Assets & PackageResources

`agent-recall-compiler` bundles its first-party operating-prompt recipe catalog and consumer skill under `resources/skills/agent-recall-consumer/`.

Consumers and orchestrators can resolve asset paths programmatically via `voku\AgentRecallCompiler\PackageResources` instead of constructing fragile relative paths in `vendor/`:

```php
use voku\AgentRecallCompiler\PackageResources;

// Path to bundled operating-prompts.json catalog
$catalogPath = PackageResources::consumerOperatingPrompts();

// Path to bundled operating-prompts.metadata.json
$metadataPath = PackageResources::consumerOperatingPromptsMetadata();

// Root directory for bundled skills (resources/skills/)
$skillsRoot = PackageResources::skillsRoot();
```

---

## Generated artifacts

A **successful** compile writes these core artifacts:

| Artifact | Purpose |
| --- | --- |
| `recall.bundle.json` | Canonical replayable task snapshot with selected learning, provider facts, and source digests. |
| `facts.json` | Compact structured facts for consumers such as `agent-loop`. |
| `selection-report.json` | Deterministic selection/exclusion reasoning, effective scope, and `context_explain` provenance. |
| `system.md` | Human/model-readable Recall briefing and selected prompt construction material. |
| `validation-plan.md` | Required validation commands, hard-constraint identifiers, and provenance. |
| `meta.json` | Technical metadata and hashes for immutable generated artifacts. |
| `recall-log.draft.json` | Editable close-out draft for guidance and operating-prompt outcomes; deliberately excluded from immutable output hashes. |
| `compilation-receipt.json` | Successful-compilation receipt containing `compilation_id`, bundle digest, and operational timestamp; used by the public PHP API and excluded from replay identity. |

Conditional artifacts:

- `verification-plan.json` and verifier-owned `verification-key.json` are written only when compilation has a map index and exactly one task target; stale copies are removed when that verification contract is not applicable.
- `feedback-assessment.draft.json` is written when non-empty `--feedback` input is supplied for evidence-backed assessment.

`context_explain` is not another file. It is stored in `selection-report.json` and rendered into `system.md` when there are explainable items.

Compilation fails closed when selected input cannot form a coherent trusted instruction set. A Recall selection conflict writes blocked `meta.json` and aborts before the successful artifact set is emitted; malformed CLI/task input can fail earlier. Empty guidance is also valid; Recall does not invent synthetic `none` guidance merely to make the artifacts look populated.

---

## Project documents and constraints

Recall can include Git-tracked Skills and ADRs through a bounded document manifest. Documents are selected by explicit path-scope overlap, tag overlap, or project-wide scope; the compiler does not scan an arbitrary documentation tree and ask an LLM what looks useful.

Active hard constraints are loaded from configured manifests and bring their own exact validation commands. Conflicting, inactive, superseded, malformed, or validation-incomplete constraints fail compilation rather than silently weakening the task contract.

For provider, precedence, and artifact details, see [Recall provider architecture](docs/recall-provider-architecture.md).

---

## Documentation

Start with the [documentation index](docs/README.md).

Important design and integration references:

- [Design principles](docs/design-principles.md)
- [CLI reference](docs/cli-reference.md)
- [Operating prompt recipes](docs/operating-prompts.md)
- [Prompt primitives](docs/prompt-primitives.md)
- [Context Explain](docs/context-explain.md)
- [Recall provider architecture](docs/recall-provider-architecture.md)
- [Guidance event history](docs/guidance-events.md)
- [LearningNote precedents](docs/learning-note-precedents.md)
- [Embedding Recall](docs/embedding.md)
- [Public PHP API](docs/public-api.md)
- [Dependency readiness](docs/dependency-readiness.md)

Bundled package skills live under `resources/skills/`:

- [`agent-recall-consumer`](resources/skills/agent-recall-consumer/SKILL.md) for consumers compiling Recall context and recording outcomes.
- [`agent-recall-compiler-maintainer`](resources/skills/agent-recall-compiler-maintainer/SKILL.md) for changes to this package.

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

> Recall compiles bounded, explainable, replayable context and prompt semantics and can persist immutable outcome evidence; it does not become the authority that executes work, approves lifecycle transitions, or decides durable Learning policy.

## License

MIT. See [LICENSE](LICENSE).
