# Recall provider architecture

Recall is the deterministic retrieval of task-relevant knowledge; it is not a
synonym for learning selection. The compiler therefore owns orchestration and
artifact generation, not the storage format or lifecycle of every knowledge
source. It never calls a model and never executes the generated L2 prompt.

## Why the previous shape did not scale

The original compile command directly opened `MEMORY.md`, proposals,
constraints, and histories. That made the package learning-centric and left
session, board, and map context to separate `agent-loop` paths. It also let a
workflow compile before a task brief was approved. Adding another source would
have enlarged that command and created another non-replayable assembly path.

This migration keeps the existing package, but separates these concerns:

| Responsibility | Source of truth | Adapter / owner |
| --- | --- | --- |
| task goal, scope, non-goals, validation | approved `agent-session` work brief | `task-context` provider |
| task priority, lane, handoff | typed `agent-kanban` card | `agent-loop` projection plus `kanban-context` provider |
| project-wide memory | tracked `MEMORY.md` | `memory` provider |
| promoted guidance, constraints, outcomes | `agent-learning` root | `agent-learning` provider |
| symbols and locations | generated `agent-map` index | `agent-map` provider |
| Skills and ADRs | Git-tracked project documents | explicit `project-documents` manifest provider |

The board projection is intentionally JSON rather than a Markdown parser in
this package. `agent-kanban` keeps ownership of card syntax and policy; recall
receives only a revision-pinned fact. The same rule applies to future sources:
their owner supplies a read-only provider or stable projection, while recall
only composes returned facts.

## Provider contract and common metadata

Every `RecallProvider` declares a stable id, contract version, source paths,
and whether it is required. For one sealed task brief it returns a source
digest plus `RecallFact` values. A fact has these common fields:

- `id`, `type`, `authority`, `source_ref`, and `scope` identify it and make it
  independently inspectable.
- `payload` is structured source content, not an opaque prompt fragment.
- `conflict_key`, `priority`, and `lifecycle` make precedence explicit.

The compiler rejects duplicate provider ids. It snapshots provider manifests
and source digests in the canonical bundle, so a later run can explain which
input changed.

## Compilation pipeline

1. Normalize inline input or a work brief into `TaskBrief`. Governed workflow
   approval passes the approved session work brief, never a guessed file list.
2. Invoke each registered provider read-only, in provider-id order.
3. Resolve generic fact conflicts: explicit `priority`, then documented
   authority precedence. Equal-precedence facts with differing payloads block
   compilation; they are not chosen lexicographically. Equal payloads are
   deduplicated deterministically.
4. Run the existing, typed learning/constraint selection engine. Its conflicts
   remain fail-closed.
5. Serialize `recall.bundle.json` through canonical JSON, then render the L2
   `system.md` and validation plan from that bundle. Rendering is not execution.

Provider relevance belongs at the provider boundary. For example, the project
document provider uses exact/prefix scope matching from a Git-tracked manifest
and a fixed excerpt limit; it neither performs semantic retrieval nor lets a
model decide what to include.

## Artifacts

- `recall.bundle.json`: canonical selection, resolved facts, source snapshot,
  and conflict decisions; the replay/audit anchor.
- `facts.json`: compact consumer view of resolved non-learning facts.
- `selection-report.json`: learning and constraint selection explanation.
- `system.md` and `validation-plan.md`: deterministic renderings for a human or
  agent harness. They are generated, never executed by the compiler.
- `meta.json`: hashes of immutable generated artifacts.
- `compilation-receipt.json`: operational timestamp only; excluded from the
  replay identity.

## Project document manifest

Projects opt in deliberately; the compiler never scans every Markdown file.
Keep the manifest in Git beside the project policy. Sources are relative to
the manifest and `max_chars` is a hard token-growth guard.

```json
{
  "schema_version": "1.0",
  "documents": [
    {
      "id": "project.php-security-boundaries",
      "type": "skill",
      "source": "infra/doc/agents/skills/php-security-boundaries/SKILL.md",
      "scope": ["modules/security/"],
      "max_chars": 2500
    },
    {
      "id": "adr.session-boundary",
      "type": "adr",
      "source": "docs/adr/004-session-boundary.md",
      "scope": ["lib/framework/session/"],
      "priority": 1,
      "conflict_key": "session-boundary"
    }
  ]
}
```

Compile it explicitly with `--document-manifest path/to/recall-documents.json`.
An ADR wins over a skill with the same conflict key at equal explicit priority;
two skills of equal precedence with different content block until a maintainer
makes the decision in source control.

## Migration and deliberately deferred boundary

1. Existing inline compile and legacy task-brief callers remain compatible.
2. `workflow plan` creates or revises a candidate brief only. `workflow
   approve` seals it, writes an optional typed board projection, and compiles
   recall from that exact input. Existing map indices receive the host root so
   an index built in Docker can still be freshness-checked on the host.
3. Projects add at most a few scoped document entries; a broad global skill
   dump is not a migration strategy.
4. Legacy board Markdown without `kanban.config.json` is intentionally skipped
   instead of guessed. Migrate it to the typed board contract before enabling
   board recall facts.
5. Outcome append still has a compatibility command in this package. A future
   `agent-learning` append/import command can take over that mutation; do not
   move it until duplicate protection and immutable-history checks are proven.

## Small IT-Portal follow-ups

1. Add one Git-tracked document manifest with only the two skills that are
   demonstrably useful for the first task family; measure the resulting bundle
   size before adding another entry.
2. Build `.agent-map/php-symbols.json` in the normal Docker root, then compile
   through `workflow approve`; `--map-root` now verifies it against the host
   checkout instead of treating the container path as fresh by assumption.
3. Keep the current legacy board out of recall until it has a typed
   `todo/kanban.config.json` and cards accepted by `agent-kanban`; this is a
   small, explicit migration rather than a second Markdown parser.
