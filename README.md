# Agent Recall Compiler (`voku/agent-recall-compiler`)

Deterministic L2 Meta-Prompt Compiler and Briefing Manager for Coding Agents.

[![Build Status](https://github.com/voku/agent-recall-compiler/actions/workflows/ci.yml/badge.svg)](https://github.com/voku/agent-recall-compiler/actions)
[![License](https://img.shields.io/github/license/voku/agent-recall-compiler.svg)](LICENSE)

This package forms the **recall layer** of the governed agent learning loop. It turns approved learnings (managed by `voku/agent-learning`) into precise, context-aware meta-prompts for subsequent coding sessions. 

Rather than overloading an LLM's system prompt with every rule ever created, the Recall Compiler selects only the rules relevant to the files the agent is about to modify. It also warns the agent of past rejections and failures to prevent repeating mistakes.

---

## Architecture & Workflow

```
                  ┌────────────────────────┐
                  │   Task File Scopes     │
                  └───────────┬────────────┘
                              │ (Matches scope prefixes)
                              ▼
┌──────────────┐    ┌──────────────────┐    ┌──────────────┐
│  MEMORY.md   │───►│  Recall Compiler │◄───│  History &   │
│ (Global mem) │    │  (Select Engine)  │    │   Outcomes   │
└──────────────┘    └────────┬─────────┘    └──────────────┘
                             │
            ┌────────────────┼────────────────┬──────────────┐
            ▼                ▼                ▼              ▼
     ┌───────────┐    ┌─────────────┐   ┌───────────┐  ┌───────────┐
     │ system.md │    │ validation- │   │ meta.json │  │ recall-   │
     │ (Briefing)│    │   plan.md   │   │  (Log)    │  │ log.draft │
     └───────────┘    └─────────────┘   └───────────┘  └───────────┘
```

---

## Key Features

- **Deterministic Scope Matching**: Evaluates the paths targeted by a task against the scopes of approved rules. Selects global rules (`MEMORY.md` and `/` or `*` scopes) along with sub-path specific active skills or constraints.
- **Constraint Manifests**: Loads active hard constraints from `constraints/active/*.json` or a configured `active_constraints_dir` and selects them by path-scope overlap instead of semantic similarity.
- **Conflict Detection**: Blocks compilation when selected active rules target the same codebase element or duplicate directive wording would give the coding agent contradictory instructions.
- **Contradiction Guard**: Blocks compilation when selected guidance matches the target patterns of previously rejected proposals.
- **Outcome-Driven Insights**: Inspects outcome logs to alert the agent if a selected rule was previously marked as `HARMFUL` or `IRRELEVANT` in past sessions, including developer comments.
- **Observable Usefulness Signals**: Separates `selected_count` from `helpful_count`, `irrelevant_count`, `harmful_count`, and `violation_detected_count`. Selection means a rule entered the prompt; it is not treated as proof that the rule improved the task.
- **Validation Briefing**: Dynamically compiles selected guidance checks and selected active constraint commands into an authoritative validation plan with required rule identifiers.
- **Loop Closure**: Prepares draft outcome feedback files so the agent can easily record what rules were helpful, irrelevant, or harmful at the end of the coding session.
- **Immutable Guidance Events**: On governed close-out, appends recall-selection events and per-guidance outcome events for deterministic projection by `voku/agent-learning`.

---

## Installation

Install via Composer:

```bash
composer require --dev voku/agent-recall-compiler
```

---

## CLI Usage

The package exposes a binary at `vendor/bin/agent-recall-compiler` supporting two main operations:

Learning roots may define `config.json` to avoid hard-coding the active constraint manifest directory:

```json
{
  "schema_version": "1.0",
  "active_constraints_dir": "constraints/active"
}
```

Relative paths are resolved from the learning root. Without configuration, the compiler keeps the legacy `constraints/active` and `constraints` lookup paths.

## Starter Integration Pattern

Use the examples instead of embedding a long recall policy in every task:

- [examples/agent-learning/config.json](examples/agent-learning/config.json): starter recall-related learning-root policy.
- [examples/agents/skills/project-agent-recall/SKILL.md](examples/agents/skills/project-agent-recall/SKILL.md): optional repo-local recall wrapper.
- [skills/agent-recall-consumer/SKILL.md](skills/agent-recall-consumer/SKILL.md): package-neutral consumer skill.

Copy this shorter contract into `AGENTS.md`, an existing learning/guidance skill, or a pre/post-task hook:

```text
Before editing, run:
vendor/bin/agent-recall-compiler compile \
  --root infra/doc/agent-learning \
  --task "<ticket-or-TODO@id>" \
  --description "<short task description>" \
  --file "<path touched by this task>" \
  --output-dir ".agent-recall/current"

Read:
- .agent-recall/current/system.md
- .agent-recall/current/validation-plan.md

Before final response:
1. Run the validation plan.
2. Complete recall-log.draft.json.
3. Put every selected rule in exactly one bucket: helpful, irrelevant, or harmful.
4. Mark helpful only when the rule changed execution, prevented a mistake, or improved validation.

Then run:
vendor/bin/agent-recall-compiler log-outcome \
  --root infra/doc/agent-learning \
  --draft ".agent-recall/current/recall-log.draft.json" \
  --by "<agent-or-human>" \
  --commit "<commit-or-working-tree>"
```

Selection is not usefulness. It only proves the rule entered the prompt. Use later `helpful`, `irrelevant`, and `harmful` outcomes for promotion, review, and retirement decisions.

---

## CLI Reference

### 1. Compile a Task Briefing

Prepares the system briefing, validation plan, metadata log, and draft outcome files for an active task.

```bash
vendor/bin/agent-recall-compiler compile \
  --root infra/doc/agent-learning \
  --task "PROJECT-367" \
  --description "Implement the new region-aware menu navigation" \
  --file "src/Navigation/MenuEntry.php" \
  --file "tests/Navigation/MenuEntryTest.php" \
  --output-dir ".agent-recall/current" \
  --compilation-id "compilation.PROJECT-367.2026-06-18.001"
```

#### Inline vs. File-based Briefing
Alternatively, you can pass a path to a pre-defined JSON file containing the task metadata:

```bash
vendor/bin/agent-recall-compiler compile \
  --root infra/doc/agent-learning \
  --task-brief "task-brief.json"
```

Where `task-brief.json` is:
```json
{
  "id": "PROJECT-367",
  "description": "Implement the new region-aware menu navigation",
  "files": [
    "src/Navigation/MenuEntry.php",
    "tests/Navigation/MenuEntryTest.php"
  ]
}
```

#### Outputs Generated:
- **`system.md`**: Combined system prompt meta-prompt briefing containing selected active rules and warnings.
- **`validation-plan.md`**: Authoritative required validation commands, selected hard-constraint rule identifiers, and provenance.
- **`meta.json`**: Technical metadata recording exactly which rules and constraints were loaded.
- **`recall-log.draft.json`**: A draft outcome log template populated with one `guidance_outcomes` row per selected rule or constraint.

Compilation fails before writing a misleading briefing when selected guidance cannot be trusted as a coherent instruction set. Blocking cases include unsupported schema versions, inactive selected rules, conflicting active rules, target overlap with rejected proposals, unknown constraint engines, superseded selected constraints, constraint commands that contradict their engine, constraints without validation commands, and outcome records that reference unknown rules.

An empty-guidance compile is valid. When no active guidance, active constraints, or rejected guidance match the task scope, `selected_guidance`, `evaluated_guidance`, `selected_constraints`, `selected_rejections`, and the outcome draft guidance arrays remain empty. Close-out may record the session result, but it must not invent synthetic guidance such as `"none"` or create per-guidance `not_used`, `helpful`, `irrelevant`, `harmful`, or `applied` evidence.

#### Constraint Manifest
Active constraints are stored as small runtime manifests:

```json
{
  "schema_version": "1.0",
  "id": "constraint.project.translation.parameters",
  "engine": "phpstan",
  "rule_identifier": "project.translation.parameters",
  "scope": ["src/"],
  "validation_commands": ["vendor/bin/phpstan analyse"],
  "source_proposal": "proposal.2026-06-13.001",
  "status": "active"
}
```

---

### 2. Log Session Outcome

At the end of a coding session, once the validation commands pass and changes are committed, log the feedback to close the loop:

```bash
vendor/bin/agent-recall-compiler log-outcome \
  --root infra/doc/agent-learning \
  --draft "recall-log.draft.json" \
  --by "Lars Moelleken" \
  --commit "abc1234"
```

This appends permanent, structured selection entries to `history/recall-selections.jsonl` and per-guidance outcome entries to `history/outcomes.jsonl`, which the compiler and `voku/agent-learning` can read during future evaluations.

`recall-log.draft.json` defaults every selected rule to `outcome=unknown` and `applied=false`. Selected means the rule was included in the closed session’s selected guidance set; it is not proof of model attention, application, or usefulness.
Events are written at close-out so abandoned or repeatedly recompiled briefings do not inflate promotion evidence. Duplicate retries fail without partially appending duplicate records.

Full schema details and retry behavior are documented in [`docs/guidance-events.md`](docs/guidance-events.md). A small end-to-end fixture is available under [`examples/end-to-end`](examples/end-to-end).

---

## Development & Testing

### Bundled Agent Skills

This package ships package-specific skills under `skills/`:

- [`agent-recall-consumer`](skills/agent-recall-consumer/SKILL.md): for end users compiling L2 task briefings, reading validation plans, and logging outcomes.
- [`agent-recall-compiler-maintainer`](skills/agent-recall-compiler-maintainer/SKILL.md): for maintainers changing `voku/agent-recall-compiler` source, tests, docs, or local vendor syncs.

Generated hard constraints selected by recall are authored through the `agent-hard-constraint-author` skill shipped by `voku/agent-learning`.

Run the test suite using PHPUnit:

```bash
vendor/bin/phpunit
```

Run static analysis using PHPStan:

```bash
vendor/bin/phpstan analyse --configuration=phpstan.neon.dist
```

---

## License

This project is licensed under the MIT License. See [LICENSE](LICENSE) for details.

## Internal Pipeline and Compatibility

The public CLI, Composer API classes, JSON field names, and generated file locations remain stable, but the implementation is organized around typed internal boundaries:

1. **Task input normalization**: inline CLI input and JSON task briefs resolve to a `TaskBrief` before selection. Existing brief files using either `id` or legacy `task_id` continue to load.
2. **Root/config resolution**: `RecallRootResolver` produces a `RecallRootConfig` from explicit `--root`, `config.json`, and legacy defaults. After that point, compiler services should receive typed config instead of rediscovering paths.
3. **Guidance selection**: `RecallDecisionEngine` still returns the historical `RecallResult`, and `SelectionResult` / `GuidanceSelection` provide an additive typed adapter for the consolidated pipeline.
4. **Rendering**: renderer facades consume `SelectionResult` or the legacy `RecallResult` and preserve the current `system.md`, `validation-plan.md`, `meta.json`, and `recall-log.draft.json` shapes.
5. **Close-out**: `OutcomeCloseOutService` centralizes the typed close-out entry point while preserving `OutcomeLogger` for existing callers.

### Event Vocabulary

The compiler records observable facts only:

- `evaluated`: a guidance candidate was considered by deterministic selection.
- `eligible`: the candidate was valid for selection.
- `selected`: the candidate was included in the compiled briefing/draft set.
- `applied`: the close-out actor supplied that the guidance was applied.
- `helpful`, `irrelevant`, `harmful`, `not_used`, `unknown`: the close-out outcome value supplied for a selected guidance item.

Selection is **not** model access. Applied is **not** automatically helpful. Helpful is task-local evidence, not a universal promotion decision. Promotion and projection remain the responsibility of `voku/agent-learning`.

### Compatibility Notes

- `system.md`, `validation-plan.md`, `meta.json`, and `recall-log.draft.json` remain the supported output files.
- `meta.json` remains the technical audit file.
- `recall-log.draft.json` remains the editable close-out draft.
- Legacy outcome drafts still route through the existing compatibility path.
- Duplicate close-out retries are rejected before duplicate immutable event records are appended.
