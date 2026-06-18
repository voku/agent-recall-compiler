# Guidance Event History

`agent-recall-compiler` writes immutable usage evidence only when a session is closed with `log-outcome`.
Compile invocations alone do not increase usage counts.

## Compilation IDs

Every compile has a `compilation_id`, written to `meta.json` and `recall-log.draft.json`.
Pass one explicitly when the caller already owns a session ID:

```bash
vendor/bin/agent-recall-compiler compile \
  --root infra/doc/agent-learning \
  --task PROJECT-123 \
  --file src/Auth/UserService.php \
  --output-dir .agent-recall/current \
  --compilation-id compilation.PROJECT-123.2026-06-18.001
```

If omitted, the compiler generates a unique `compilation.<task>.<timestamp>.<random>` ID and prints it.

## Selection Events

Closed sessions append evaluated guidance to `history/recall-selections.jsonl`:

```json
{"schema_version":"1.0","id":"recall-selection.2026-06-18.001","compilation_id":"compilation.PROJECT-123.2026-06-18.001","task_id":"PROJECT-123","guidance_id":"skill.auth-context","guidance_type":"skill","eligible":true,"selected":true,"selection_reason":"scope_overlap","exclusion_reason":null,"task_files":["src/Auth/UserService.php"],"recorded_at":"2026-06-18T10:00:00+00:00"}
```

Allowed guidance types are `memory`, `skill`, and `constraint`.
Allowed selection reasons are `global`, `explicit`, `scope_overlap`, `constraint_scope`, `required_validation`, `rejected_guidance_warning`, and `outcome_warning`.
Allowed exclusion reasons are `no_scope_overlap`, `inactive`, `stale`, `superseded`, `conflicting`, `rejected`, and `invalid_schema`.

## Outcome Events

`recall-log.draft.json` contains one editable `guidance_outcomes` row per selected guidance item.
Rows default to `applied=false` and `outcome=unknown`.
The allowed outcome values are `helpful`, `irrelevant`, `harmful`, `not_used`, and `unknown`.

Close the session with:

```bash
vendor/bin/agent-recall-compiler log-outcome \
  --root infra/doc/agent-learning \
  --draft .agent-recall/current/recall-log.draft.json \
  --by "Lars Moelleken" \
  --commit abc1234
```

The command appends per-guidance events to `history/outcomes.jsonl`.
It rejects duplicate event IDs, duplicate `compilation_id + guidance_id` pairs, selected guidance missing from the draft, non-selected guidance marked applied, unknown guidance IDs, unknown schemas, malformed timestamps, and secret-like values.
Both JSONL files are updated under one lock; duplicate retry failures leave both files unchanged.

## Empty-Guidance Sessions

A compile can succeed with no selected guidance, no selected constraints, no selected rejections, and no evaluated guidance. In that case the outcome draft keeps `selected`, `guidance_outcomes`, `applied`, `helpful`, `irrelevant`, and `harmful` empty. Closing that draft is a valid session-level close-out, but it does not append per-guidance selection or outcome events because there is no guidance item to evaluate. Duplicate empty close-out retries are safe because no item-level event records are appended.

Do not represent an empty selection with a synthetic guidance ID such as `none`, and do not turn empty arrays into `not_used`, `helpful`, `irrelevant`, `harmful`, or `applied` evidence.

Selection is not model access and is not usefulness.
It only means the guidance was included in the closed session’s evaluated/selected set.
Use `applied` and explicit outcomes for promotion and review decisions in `voku/agent-learning`.
