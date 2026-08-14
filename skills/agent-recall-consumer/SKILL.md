---
name: agent-recall-consumer
description: Use voku/agent-recall-compiler to compile task-scoped Recall briefings, apply explicit operating-prompt recipes, review implementations, and record evidence-backed outcomes with the current CLI contract.
---

# Agent Recall Consumer

Use this skill when a coding agent needs task-scoped Recall guidance from the current repository. Treat the CLI and generated artifacts as deterministic inputs to the coding workflow, not as proof that work was executed or verified.

## Ownership

This directory is the canonical home for instructions and reusable recipe assets that directly exercise `agent-recall-compiler`. Recall owns its commands, output contract, review primitives, L2 construction semantics, and bundled operating-prompt catalog.

The bundled manifest is:

```text
skills/agent-recall-consumer/operating-prompts.json
```

From an installed Composer dependency:

```text
vendor/voku/agent-recall-compiler/skills/agent-recall-consumer/operating-prompts.json
```

Callers still select every recipe and provide every required argument explicitly. Bundling the catalog does not create hidden defaults.

## Current Defaults

The standalone CLI owns compact `.agent-loop` defaults:

```text
learning root: <cwd>/.agent-loop/learning
recall output: <cwd>/.agent-loop/recall/<task-id>
```

Do not copy historical project-specific roots or ad-hoc output directories into new automation. Override `--root` or `--output-dir` only when the consuming project intentionally uses a different location.

When `agent-loop` is installed, prefer its wrapper for project-owned path resolution:

```bash
vendor/bin/agent-loop init paths --format=json
vendor/bin/agent-loop recall compile --task PROJECT-123 --description "Implement region-aware navigation" --file src/Navigation/Menu.php
```

`agent-loop recall compile` resolves the configured Learning and Recall roots through the project layout. The standalone compiler uses the defaults above.

## Compile

Minimal standalone compile:

```bash
vendor/bin/agent-recall-compiler compile \
  --task PROJECT-123 \
  --description "Implement region-aware navigation" \
  --file src/Navigation/Menu.php
```

For behavioral work, provide concrete files and behavior anchors through the supported task-brief/Contract input instead of dumping the repository. Missing or conflicting governed guidance is a real blocker, not an invitation to invent a fallback.

Use a bundled L2 recipe without copying its semantics elsewhere:

```bash
vendor/bin/agent-recall-compiler compile \
  --task PROJECT-123 \
  --description "Review the current implementation as a first draft" \
  --file src/Navigation/Menu.php \
  --operating-prompt-manifest vendor/voku/agent-recall-compiler/skills/agent-recall-consumer/operating-prompts.json \
  --operating-prompt '{"id":"adversarial-review","arguments":{"minimum_failure_modes":3}}'
```

Recipe selection and arguments are task policy. A selected recipe may be L1 or L2; follow the generated `system.md` contract and do not treat prompt construction as implementation.

## Review

For an immediate context-light falsification lens:

```bash
vendor/bin/agent-recall-compiler review first-draft
```

For project/task-backed review use the current `review` subcommands exposed by `agent-recall-compiler review --help` or the owning `agent-loop review` wrapper when Loop is installed. Review output is evidence for a decision, not approval by itself.

The bundled `adversarial-review` recipe requires actual falsification attempts. Its numeric floor counts plausible failure-mode hypotheses or attack scenarios investigated, not defects that must be manufactured. `CLEAN` remains valid when those probes find no evidence-backed defect.

## Future-work Reflection

Context-light reflection is a separate prompt surface:

```bash
vendor/bin/agent-recall-compiler prompt future-work --scope project
vendor/bin/agent-recall-compiler prompt future-work --scope task
```

It does not mutate workflow state or imply that follow-up work must be created.

## Outcomes

After actual implementation and validation, complete the generated `recall-log.draft.json` honestly, then append it to learning history:

```bash
vendor/bin/agent-recall-compiler log-outcome \
  --draft .agent-loop/recall/PROJECT-123/recall-log.draft.json \
  --by agent \
  --commit working-tree
```

When the project overrides the compact layout, pass the real `--root` and draft path rather than assuming defaults. With `agent-loop`, prefer the wrapper because it resolves the configured Learning root:

```bash
vendor/bin/agent-loop recall log-outcome \
  --draft <recall-root>/PROJECT-123/recall-log.draft.json \
  --by <actor> \
  --commit <sha>
```

Treat `selected` as exposure only. Set `applied=true` only when guidance affected the work, and classify outcomes from evidence as `helpful`, `irrelevant`, `harmful`, `not_used`, or `unknown`.

## Output Expectations

A normal compile writes task artifacts below the selected Recall output directory, including:

- `system.md`: selected guidance, warnings, constraints, evidence labels, and selected operating prompts;
- `validation-plan.md`: required validation commands and rule identifiers;
- `recall.bundle.json`, `facts.json`, and `selection-report.json`: deterministic evidence/provenance;
- `meta.json`: compilation identity, selected guidance, constraints, prompt provenance, and output hashes;
- `recall-log.draft.json`: outcome template for close-out;
- verification-plan/key artifacts when the selected map target makes them applicable.

Generated files are not automatically injected into a coding model by the standalone compiler. A harness or `agent-loop` workflow must explicitly consume them.

## Evidence Rules

- Acceptance criteria are required outcomes, not evidence that they passed.
- Use `VERIFIED`, `INFERRED`, `ASSUMED`, `BLOCKED`, or `CONTRADICTED` honestly for material conclusions.
- Prior model reasoning, confidence, reviewer consensus, prompt construction, and unexecuted commands are not verification.
- If required evidence is unavailable, remain `UNKNOWN`/`BLOCKED`; do not silently weaken scope, constraints, or acceptance criteria.
- Selected context does not grant edit permission.

## Close-out Boundary

`log-outcome` appends immutable selection/outcome events under the Learning root. It does not approve durable guidance. Duplicate retries fail rather than partially appending a second outcome.

When `agent-loop` owns the governed Run, use its workflow skills for PLAN/APPROVE/CONTRACT/LEARN/CLOSE. This skill remains the canonical reference for Recall-specific commands and artifacts; it is not a second workflow lifecycle.
