---
name: project-agent-recall
description: Compile task-scoped recall before coding and log selected/helpful/irrelevant/harmful outcomes afterward.
---

# Project Agent Recall

Use this wrapper around `agent-recall-consumer` when your agent client needs a repo-local skill. If your repository already has an agent-learning or guidance-maintenance skill, call recall from that workflow instead of adding another wrapper.

## Fast Path

1. Before editing, compile recall with the task ID, short description, and known file paths.
2. Read `system.md` and `validation-plan.md`; treat compile blockers as safety issues.
3. Re-run recall if the task expands into a new file scope.
4. Before final response, run the validation plan.
5. Complete `recall-log.draft.json`: every selected rule goes into exactly one of `helpful`, `irrelevant`, or `harmful`.
6. Append the outcome with `log-outcome`.

## Commands

```bash
vendor/bin/agent-recall-compiler compile --root infra/doc/agent-learning --task PROJECT-123 --file src/Example.php --output-dir .agent-recall/current
vendor/bin/agent-recall-compiler log-outcome --root infra/doc/agent-learning --draft .agent-recall/current/recall-log.draft.json --by agent --commit working-tree
```
