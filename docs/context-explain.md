# Context Explain Plan

Recall does not only expose task context. It can also explain why that context was selected and what a receiving agent may safely infer from it.

The explain projection is deterministic and is derived from the same facts and selection decisions already used by compilation. It does not ask an LLM to invent rationale and it does not create a second source of truth.

Each explain item uses six core fields:

```text
WHAT       source, command, document, recipe, or decision being explained
WHY        why it is relevant to the current task
HOW        deterministic derivation path that established the relevance
AUTHORITY  what makes the referenced source or claim authoritative
USE        what the receiving agent may use it for
STATE      confidence in the provenance claim: verified, inferred, unknown, blocked
```

Known exclusions additionally expose `why_not`.

## Provenance is not implementation rationale

The explain plan answers questions such as:

- Why did Recall include this file?
- How was the relation to the target derived?
- Why is this command considered project-native?
- Why is this document in scope?
- Why may this dependency be read but not edited merely because it appears in context?
- Why was a candidate excluded or left UNKNOWN?

It deliberately does **not** explain why an implementation agent preferred one design, believed its patch was elegant, or rejected an alternative. That rationale can anchor a fresh reviewer and belongs to a different evidence surface.

## `HOW` and `AUTHORITY` are different

A source slice discovered through agent-map is a useful example:

```text
WHAT       src/Parser.php:40-91
WHY        requested edit target
HOW        agent-map EditContextPlan role: primary
AUTHORITY  repository_source_via_agent_map
USE        implementation_candidate
STATE      verified
```

`agent-map` explains how the source entered the context. The current repository source is what gives the slice authority. By contrast, an agent-map blind spot or omission is itself a derived navigation claim and keeps `derived_navigation` authority.

## Exact commands versus tool presence

A Composer script is executable repository evidence:

```text
WHAT       composer ci
HOW        composer.json scripts.ci
USE        verification_candidate
STATE      verified
```

An installed package proves less:

```text
WHAT       phpunit/phpunit ^11.5
HOW        composer.json require-dev
USE        capability_presence_only_do_not_infer_command
STATE      verified
```

The second item proves that PHPUnit is present. It does not prove that `vendor/bin/phpunit` is the repository's preferred validation command.

## Context-only source is not edit permission

Target-aware agent-map roles preserve usage semantics:

- `primary` -> `implementation_candidate`
- `contract` -> `compatibility_contract`
- `change_candidate` -> `inspect_and_edit_if_required`
- `verification` -> `verification`
- `dependency` / `type_definition` -> `context_only_do_not_edit_from_selection_alone`

Unknown future roles remain `UNKNOWN` and `context_only_until_verified` instead of silently widening edit permission.

## Documents, recipes, and guidance decisions

Scoped project documents explain whether selection came from path overlap, tag overlap, or project-wide policy. Operating prompts explain explicit task selection plus manifest/template provenance. Guidance decisions expose the deterministic selection reason or the exact exclusion reason already produced by the decision engine.

When the compiler cannot reconstruct a stronger explanation from emitted evidence, it keeps the item `UNKNOWN`; plausible prose is not a substitute for provenance.

## Outputs and authority boundary

`selection-report.json` contains the machine-readable `context_explain` projection. `system.md` renders the same information under `Context Explain Plan` for a receiving agent.

The canonical `recall.bundle.json` remains the source evidence. The explain plan is derived from already-bound facts and selection results, and `selection-report.json` remains covered by the normal compile output hashes. No new lifecycle or evidence authority is introduced.

`VERIFIED` describes the provenance claim in the explain item. It does not mean every statement inside the referenced source is automatically true.
