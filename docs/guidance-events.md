# Guidance Event History

`agent-recall-compiler` writes immutable Recall usage evidence only when `log-outcome` finalizes a draft. Compile invocations alone do not increase usage counts.

The durable event append surface is evidence storage, not durable-guidance policy. Promotion, weakening, retirement, or other Learning decisions remain outside Recall.

## Compilation IDs

Every successful compile has a `compilation_id`, written to `meta.json`, `recall-log.draft.json`, and `compilation-receipt.json`.
Pass one explicitly when the caller already owns a stable compilation identity:

```bash
vendor/bin/agent-recall-compiler compile \
  --root infra/doc/agent-learning \
  --task PROJECT-123 \
  --file src/Auth/UserService.php \
  --output-dir .agent-recall/current \
  --compilation-id compilation.PROJECT-123.2026-06-18.001
```

If omitted, the compiler generates a unique `compilation.<task>.<timestamp>.<random>` ID.

## Selection Events

Finalized event drafts append evaluated guidance to `history/recall-selections.jsonl`:

```json
{"schema_version":"1.0","id":"recall-selection.2026-06-18.001","compilation_id":"compilation.PROJECT-123.2026-06-18.001","task_id":"PROJECT-123","guidance_id":"skill.auth-context","guidance_type":"skill","eligible":true,"selected":true,"selection_reason":"scope_overlap","exclusion_reason":null,"task_files":["src/Auth/UserService.php"],"recorded_at":"2026-06-18T10:00:00+00:00"}
```

Selection means that deterministic Recall evaluation reached that guidance item and records whether it was selected. It does not prove model access, application, or usefulness.

## Guidance outcome placeholders are not feedback

`recall-log.draft.json` initially contains one `guidance_outcomes` placeholder row per selected guidance item:

```json
{
  "guidance_id": "skill.auth-context",
  "selected": true,
  "applied": false,
  "outcome": "unknown",
  "comment": null
}
```

Those defaults are compiler scaffolding, not finalized evidence. `log-outcome` rejects an untouched `unknown` row because otherwise the compiler's own placeholder would be persisted as though a session had judged the guidance.

For a finalized row:

- allowed outcomes are `helpful`, `irrelevant`, `harmful`, `not_used`, and `unknown`;
- `helpful`, `irrelevant`, and `harmful` require a non-empty justification comment;
- `helpful` and `harmful` require `applied=true`;
- an explicit `unknown` requires a non-empty comment explaining why the guidance could not be judged.

When the session genuinely cannot judge selected guidance, do **not** manufacture `not_used` or `irrelevant` merely to satisfy completeness. Remove the unjudged placeholder rows and set a non-empty top-level `guidance_outcomes_withheld_reason`. The corresponding selection events retain that reason so downstream consumers can distinguish deliberate absence from accidentally dropped feedback.

Silent omission without a withholding reason fails.

## Finalize outcome evidence

```bash
vendor/bin/agent-recall-compiler log-outcome \
  --root infra/doc/agent-learning \
  --draft .agent-recall/current/recall-log.draft.json \
  --by "Lars Moelleken" \
  --commit abc1234
```

The event-draft path appends:

- evaluated guidance selections to `history/recall-selections.jsonl`;
- finalized per-guidance outcomes to `history/outcomes.jsonl`;
- selected operating-prompt outcomes to `history/operating-prompt-outcomes.jsonl` when such rows exist.

All three histories use one event-history lock. Duplicate event IDs and duplicate `compilation_id + subject` pairs are rejected before append. The writer snapshots existing content and restores all affected files if a later append in the same batch fails, so a partial multi-file history update is not accepted as success.

The redaction guard also rejects secret-like event payloads before they are persisted.

## Operating-prompt outcomes

Selected operating prompts receive separate `operating_prompt_outcomes` rows in the draft. They carry the selected argument digest so outcome evidence stays bound to the exact recipe invocation.

`helpful`, `irrelevant`, and `harmful` operating-prompt outcomes require evidence; `helpful` and `harmful` also require `applied=true`. These events remain separate from normal guidance outcomes in `history/operating-prompt-outcomes.jsonl`.

Outcome counts may later inform review of recipe quality. They do not automatically rewrite the selected recipe or mutate durable policy.

## Empty-guidance sessions

A compile can succeed with no selected guidance, no selected constraints, no selected rejections, and no evaluated guidance. In that case the guidance selection/outcome arrays remain empty.

Closing such a draft is valid. No per-guidance selection or outcome events are appended because there is no guidance item to evaluate. Do not represent an empty selection with a synthetic guidance ID such as `none`, and do not turn empty arrays into `not_used`, `helpful`, `irrelevant`, `harmful`, or `applied` evidence.

## Event vocabulary

Keep these facts separate:

- `evaluated`: deterministic Recall considered the guidance candidate;
- `eligible`: the candidate was valid for selection;
- `selected`: the candidate entered the selected guidance set;
- `applied`: the close-out actor says the guidance affected execution;
- `helpful`, `irrelevant`, `harmful`, `not_used`, `unknown`: task-local outcome values supplied at close-out;
- `outcome_withheld_reason`: the session deliberately recorded that selected guidance could not be judged.

Selection is not usefulness. Applied is not automatically helpful. A task-local outcome is evidence for later Learning review, not a universal promotion or retirement decision.
