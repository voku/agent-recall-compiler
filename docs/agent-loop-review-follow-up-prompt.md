# Follow-up prompt for `voku/agent-loop`

Integrate the recall-compiler dogfood review boundary into the `agent-loop` workflow.

## Goal

Add an `agent-loop review` namespace that can call deterministic review helpers before task close and generate L2 prompts without invoking an LLM directly.

## Requirements

1. Add commands:
   - `agent-loop review blindspots <task-id>`: writes Markdown, JSON, and prompt files under `.agent-loop/reviews/`.
   - `agent-loop review code <task-id>`: writes `.agent-loop/reviews/<task-id>.code.prompt.md`.
2. Collect task/workflow artifacts from task cards, session notes, recall outputs, validation plans, and existing review reports.
3. Reject unsafe task IDs. Use a regex that requires the first character to be alphanumeric and disallows `..`.
4. Handle unreadable `meta.json` safely: check `file_get_contents()` for `false` before `json_decode()`.
5. Make reports deterministic and auditable; do not call an LLM from the CLI.
6. Wire the command into dispatcher help and README examples.
7. Add PHPUnit coverage for routing, path validation, report writing, code prompt generation, and the two dogfood fixes above.

## Dogfood correction from `agent-recall-compiler`

The recall compiler adaptation found two implementation details worth enforcing in `agent-loop`:

- Task IDs such as `.`, `_foo`, or `-foo` should not pass path validation. Use `\A[A-Za-z0-9][A-Za-z0-9._-]*\z` plus an explicit `..` rejection.
- Never cast `file_get_contents($metaPath)` directly to string before JSON decoding. Store it, return an empty artifact set on `false`, and decode only a real string.

## Expected validation

Run:

```bash
composer test
composer phpstan
```
