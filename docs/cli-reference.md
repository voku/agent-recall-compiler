# CLI reference

`agent-recall-compiler` exposes `vendor/bin/agent-recall-compiler` for deterministic Recall compilation, outcome logging, prompt primitives, and review artifacts.

The README keeps the entry path short; this document holds the operational details that would otherwise turn the README back into a second manual.

## Learning-root configuration

A Learning root may define `config.json` so consumers do not hard-code the active constraint manifest directory:

```json
{
  "schema_version": "1.0",
  "active_constraints_dir": "constraints/active"
}
```

Relative paths are resolved from the Learning root. Without configuration, the compiler keeps the legacy `constraints/active` and `constraints` lookup paths.

## Compile a task briefing

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

The compiler prepares replayable task evidence, the human/model-readable briefing, validation obligations, and outcome drafts. It does not execute implementation work or validation commands.

### Exact method target

Use `--target` with an `agent-map` index when the task has an exact `Class::method` edit target:

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

`--target` is repeatable and requires `--map-index`. The index may be JSON or TOON. `--map-root` changes only the runtime checkout root used for source freshness and materialization, which is useful when the index was built inside Docker.

The map-derived **effective scope** contains primary, contract, direct change-candidate, and verification files. Dependencies and type definitions remain context-only and do not select path-scoped guidance merely because they were shown to the agent.

### Candidate navigation without an exact target

When `agent-map` has a derived search index, `--map-search-index` can add ranked candidates for a task that does not yet have an exact `--target`:

```bash
vendor/bin/agent-map search-index build --database ".agent-map/search.sqlite"

vendor/bin/agent-recall-compiler compile \
  --root infra/doc/agent-learning \
  --task "PROJECT-367" \
  --description "Dunning reminder mails are sent twice for the same overdue invoice" \
  --map-index ".agent-map/php-symbols.json" \
  --map-root "$PWD" \
  --map-search-index ".agent-map/search.sqlite" \
  --map-search-limit 8 \
  --output-dir ".agent-recall/current"
```

`--map-search-index` requires `--map-index`, and both must describe the same map snapshot. A snapshot mismatch is reported as a stale status fact rather than silently ranking against an older index.

Ranked candidates are leads, not resolved navigation. They do not enter the effective scope and do not select path-scoped guidance by themselves.

## File-based task input

A standalone compile may read task metadata from a JSON file:

```bash
vendor/bin/agent-recall-compiler compile \
  --root infra/doc/agent-learning \
  --task-brief "task-brief.json"
```

Example:

```json
{
  "id": "PROJECT-367",
  "description": "Implement the new region-aware menu navigation",
  "files": [
    "src/Navigation/MenuEntry.php",
    "tests/Navigation/MenuEntryTest.php"
  ],
  "behavior_anchors": [
    "HTTP request -> MenuEntry resolver -> rendered navigation"
  ],
  "targets": [
    "App\\Navigation\\MenuEntry::resolve"
  ]
}
```

`targets` contains exact `Class::method` values resolved by `agent-map`. `behavior_anchors` is optional and is intended for behavioral work where concrete request, runtime, consumer, data, or integration seams must be inspected or verified.

Material conclusions rendered into the briefing distinguish `VERIFIED`, `INFERRED`, `ASSUMED`, `BLOCKED`, and `CONTRADICTED`. Plausible explanations and agent consensus are not evidence.

For governed `agent-loop` input, see [Operating prompt recipes](operating-prompts.md#governed-input). In that path, `--task-brief` points to the governed Recall envelope bound to an approved Contract rather than to a Session-private work brief.

## Generated compile artifacts

A normal compile may generate:

- `recall.bundle.json` — canonical replayable task snapshot with selected Learning, resolved provider facts, source digests, effective scope, and conflict decisions;
- `facts.json` — compact structured provider facts for consumers;
- `selection-report.json` — deterministic selection/exclusion explanation and context provenance;
- `system.md` — Recall briefing plus selected operating-prompt construction material;
- `validation-plan.md` — required validation commands, hard-constraint identifiers, and provenance;
- `meta.json` — technical metadata and immutable artifact hashes;
- `compilation-receipt.json` — operational compile timestamp, excluded from replay identity where applicable;
- `recall-log.draft.json` — editable close-out draft with selected guidance and operating-prompt outcome rows.

Compilation fails before writing a misleading briefing when selected input cannot be trusted as one coherent instruction set. Examples include unsupported schemas, inactive or conflicting selected guidance, scope-relevant rejected proposals, unknown constraint engines, superseded constraints, invalid constraint commands, missing required validation commands, and outcome references to unknown rules.

An empty-guidance compile is valid. The compiler does not invent synthetic guidance such as `none` and does not manufacture `applied`, `helpful`, `irrelevant`, or `harmful` evidence for an empty selection.

## Constraint manifest

Active constraints are small runtime manifests, for example:

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

Selected active constraints contribute authoritative validation obligations. Invalid, contradictory, superseded, or incomplete constraint input fails closed.

## Project-document manifest

`--document-manifest` adds Git-tracked Skills and ADRs to Recall through a bounded manifest instead of scanning an arbitrary documentation tree.

Example:

```json
{
  "schema_version": "1.0",
  "documents": [
    {
      "id": "project.shell-tooling",
      "type": "skill",
      "source": "../agents/skills/shell-tooling/SKILL.md",
      "scope": ["/"],
      "tags": ["tooling"],
      "max_chars": 2200
    },
    {
      "id": "project.adr-database-layer",
      "type": "adr",
      "source": "../ADR_DatabaseLayer.md",
      "scope": ["src/Database/"],
      "max_chars": 4000
    }
  ]
}
```

`source` is resolved relative to the manifest and must stay relative. `type` is `skill` or `adr` and determines the default authority (`project_skill` / `project_adr`); `authority`, `priority`, and `conflict_key` may override the defaults.

`max_chars` is an integer from 1 to 12000, with a default of 4000. Truncation is made explicit in the excerpt and fact payload.

A document is selected when its path scope overlaps the task, when it shares an exact project-defined tag with the task, or when it is project-wide. Project-wide scope may be expressed as empty scope, `["/"]`, or `["*"]`.

See [Recall provider architecture](recall-provider-architecture.md#project-document-manifest) for precedence and provider semantics.

## Log session outcome

After execution and required validation, complete the generated draft and log the session outcome:

```bash
vendor/bin/agent-recall-compiler log-outcome \
  --root infra/doc/agent-learning \
  --draft ".agent-recall/current/recall-log.draft.json" \
  --by "<agent-or-human>" \
  --commit "<commit-or-working-tree>"
```

The command appends immutable selection and per-guidance outcome events under the Learning history. Selected guidance defaults to `applied=false` and `outcome=unknown` until the close-out actor supplies evidence.

Selection only proves that guidance entered the selected set. It does not prove model access, application, or usefulness.

See [Guidance event history](guidance-events.md) for event shape and retry behavior.

## Review artifacts

After implementation validation and before lifecycle close-out, Recall can generate deterministic blind-spot reports and review prompts without calling an LLM from the CLI:

```bash
vendor/bin/agent-recall-compiler review blindspots PROJECT-367 \
  --output-dir ".agent-recall/current"

vendor/bin/agent-recall-compiler review code PROJECT-367 \
  --output-dir ".agent-recall/current"
```

`review blindspots` writes the blind-spot Markdown/JSON/prompt artifact set under the Recall reviews directory. `review code` writes the code-review prompt artifact.

Generated prompts are handoff artifacts for a receiving reviewer or harness. They do not approve code, acknowledge review, close a Run, or mutate durable Learning.

Peer or agent feedback is treated as an untrusted claim until current repository evidence, focused history, or safe runtime observation establishes it.

For the different prompt/review jobs, see [Prompt primitives](prompt-primitives.md).

## First-draft review and reflection primitives

Context-light first-draft review:

```bash
vendor/bin/agent-recall-compiler review first-draft
```

Future-work reflection:

```bash
vendor/bin/agent-recall-compiler prompt future-work --scope project
vendor/bin/agent-recall-compiler prompt future-work --scope task
```

Guidance-gap journal prompt:

```bash
vendor/bin/agent-recall-compiler prompt guidance-gaps
```

These primitives intentionally have different reasoning jobs and should not be collapsed into one universal prompt shape. See [Prompt primitives](prompt-primitives.md).

## Development validation

The repository's own supported validation entry point is:

```bash
composer ci
```

Focused commands:

```bash
composer test
composer phpstan
```

`composer ci` runs strict Composer validation, PHPUnit, and PHPStan.
