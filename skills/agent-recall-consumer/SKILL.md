---
name: agent-recall-consumer
description: Use voku/agent-recall-compiler in a consuming repository to compile L2 task briefings, select active guidance and constraints by scope, produce validation plans, and log outcomes after a session.
---

# Agent Recall Consumer

Use this skill when a project wants task-scoped L2 prompt material from a learning root. Recall should select only relevant active guidance and hard constraints for the files in the current task.

For a repo-local wrapper, copy the shorter example in `examples/agents/skills/project-agent-recall/SKILL.md`. For starter config, copy `examples/agent-learning/config.json`.

## Fast Path

1. Validate that the learning root contains proposals, history, and any active constraints needed for the task.
2. Add `active_constraints_dir` to learning-root `config.json` when manifests are not stored in `constraints/active`.
3. Compile from a task brief or inline task data, always passing concrete file paths when available.
4. Treat compile-blocking conflicts as real: inactive guidance, duplicate directives, contradictory rejected proposals, unknown constraint engines, or invalid outcome references should be fixed before using the briefing.
5. Use `validation-plan.md` as the authoritative command list for selected guidance and constraints.
6. At session end, complete `recall-log.draft.json` and append it with `log-outcome` after validation succeeds.
7. Treat `selected` as exposure only. Mark each selected rule as exactly one of `helpful`, `irrelevant`, or `harmful`; do not leave selected guidance unclassified and do not mark it helpful by default.

## Commands

```bash
vendor/bin/agent-recall-compiler compile \
  --root infra/doc/agent-learning \
  --task PROJECT-123 \
  --description "Implement region-aware navigation" \
  --file src/Navigation/Menu.php \
  --output-dir .agent-recall-output
```

```bash
vendor/bin/agent-recall-compiler log-outcome \
  --root infra/doc/agent-learning \
  --draft .agent-recall-output/recall-log.draft.json \
  --by agent \
  --commit working-tree
```

## Output Expectations

- `system.md`: selected guidance, warnings, and hard-constraint execution contract.
- `validation-plan.md`: required commands and rule identifiers.
- `meta.json`: selected guidance and constraint IDs.
- `recall-log.draft.json`: outcome template to complete after the task; usefulness buckets start empty by design.
