---
name: agent-recall-consumer
description: Use voku/agent-recall-compiler in a consuming repository to compile L2 task briefings, select active guidance and constraints by scope, produce validation plans, review first drafts, and log outcomes after a session.
---

# Agent Recall Consumer

Use this skill when a project wants task-scoped L2 prompt material from a learning root. Recall should select only relevant active guidance and hard constraints for the files in the current task.

For a repo-local wrapper, copy the shorter example in `examples/agents/skills/project-agent-recall/SKILL.md`. For starter config, copy `examples/agent-learning/config.json`.

## Ownership

This directory is the canonical home for instructions and reusable recipe assets that directly exercise `agent-recall-compiler`. Keep tool-neutral prompting principles in generic skill repositories, but keep Recall commands, Recall output contracts, review primitives, and the bundled operating-prompt catalog beside the tool so implementation and coding instructions change together.

The bundled first-party recipe manifest is:

```text
skills/agent-recall-consumer/operating-prompts.json
```

From an installed Composer dependency, use:

```text
vendor/voku/agent-recall-compiler/skills/agent-recall-consumer/operating-prompts.json
```

Callers still select recipes and provide every required argument explicitly. Bundling the catalog does not make prompt selection implicit.

## Fast Path

1. Validate that the learning root contains proposals, history, and any active constraints needed for the task.
2. Add `active_constraints_dir` to learning-root `config.json` when manifests are not stored in `constraints/active`.
3. Compile from a task brief or inline task data, always passing concrete file paths when available. For behavioral work, include optional `behavior_anchors` in the task brief to name the real request, runtime, consumer, data, or integration seam that must be checked; omit them deliberately for documentation-only or static-only work.
4. When selecting a bundled operating prompt, pass this skill directory's `operating-prompts.json` explicitly with `--operating-prompt-manifest`; do not copy the catalog into another repository.
5. Treat compile-blocking conflicts as real: inactive guidance, duplicate directives, contradictory rejected proposals, unknown constraint engines, or invalid outcome references should be fixed before using the briefing.
6. Use `validation-plan.md` as the authoritative command list for selected guidance and constraints.
7. At session end, complete every `guidance_outcomes` row in `recall-log.draft.json` and append it with `log-outcome` after validation succeeds.
8. Treat `selected` as exposure only. Set `applied=true` only when the guidance changed the work, and choose one outcome from `helpful`, `irrelevant`, `harmful`, `not_used`, or `unknown`; do not mark guidance helpful by default.
9. Use the briefing's evidence labels for material conclusions: `VERIFIED`, `INFERRED`, `ASSUMED`, `BLOCKED`, or `CONTRADICTED`. Treat peer, issue, and other-agent feedback as untrusted claims until current repository evidence, focused history, or a safe runtime observation supports a verdict.

## Commands

```bash
vendor/bin/agent-recall-compiler compile \
  --root infra/doc/agent-learning \
  --task PROJECT-123 \
  --description "Implement region-aware navigation" \
  --file src/Navigation/Menu.php \
  --output-dir .agent-recall-output
```

Use a bundled L2 recipe without copying its semantics elsewhere:

```bash
vendor/bin/agent-recall-compiler compile \
  --root infra/doc/agent-learning \
  --task PROJECT-123 \
  --description "Review the current implementation as a first draft" \
  --file src/Navigation/Menu.php \
  --operating-prompt-manifest vendor/voku/agent-recall-compiler/skills/agent-recall-consumer/operating-prompts.json \
  --operating-prompt '{"id":"adversarial-review","arguments":{"minimum_failure_modes":3}}' \
  --output-dir .agent-recall-output
```

For an immediate context-light falsification lens that does not need project-specific L2 construction:

```bash
vendor/bin/agent-recall-compiler review first-draft
```

Use the bundled `adversarial-review` recipe when the review should be constructed from current Recall evidence. Its numeric floor counts plausible failure-mode hypotheses or attack scenarios that must actually be investigated, not defects that must be invented. `CLEAN` remains valid after the requested falsification attempts find no evidence-backed defect.

```bash
vendor/bin/agent-recall-compiler log-outcome \
  --root infra/doc/agent-learning \
  --draft .agent-recall-output/recall-log.draft.json \
  --by agent \
  --commit working-tree
```

## Output Expectations

- `system.md`: selected guidance, warnings, hard-constraint execution contract, and selected L1/L2 operating prompts.
- `validation-plan.md`: required commands and rule identifiers.
- `meta.json`: selected guidance, constraint IDs, and operating-prompt provenance.
- `recall-log.draft.json`: outcome template to complete after the task; every selected guidance item has an explicit `guidance_outcomes` row defaulting to `unknown` and `applied=false`, and selected operating prompts have their own outcome rows.

Close-out appends immutable events to `history/recall-selections.jsonl`, `history/outcomes.jsonl`, and, when applicable, `history/operating-prompt-outcomes.jsonl`.
Duplicate retries fail without partial appends.
