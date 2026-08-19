# Recall provider architecture

Recall is the deterministic retrieval and composition of task-relevant knowledge; it is not a synonym for Learning selection. The compiler owns orchestration and Recall artifact generation, not the storage format or lifecycle of every knowledge source. It never calls a model and never executes the generated L2 construction prompt.

## Why the previous shape did not scale

The original compile command directly opened `MEMORY.md`, proposals, constraints, and histories. That made the package Learning-centric and left board and map context to separate orchestration paths. Adding another source would have enlarged the command and created another non-replayable assembly path.

The provider architecture separates source ownership from Recall composition:

| Responsibility | Source of truth | Adapter / owner |
| --- | --- | --- |
| task goal, scope, non-goals, acceptance criteria, validation | standalone inline/JSON task input, or an approved durable Contract referenced by `governed_recall_input` | `TaskBriefParser` + `task-context` provider |
| governed Run lineage and Contract binding | envelope `run_id` + Contract path / revision / SHA-256 | `TaskBriefParser` validates; lifecycle host owns the envelope |
| task priority, lane, handoff | typed `agent-kanban` projection | host projection + `kanban-context` provider |
| project-wide memory | tracked `MEMORY.md` | `memory` provider |
| promoted guidance, constraints, outcomes | `agent-learning` root | `agent-learning` provider |
| project tool/capability evidence | bounded project manifests/config names | `project-capabilities` provider |
| symbols, relations, and bounded edit context | generated `agent-map` index | `agent-map` provider |
| Skills and ADRs | Git-tracked project documents | explicit `project-documents` manifest provider |
| selected operating-prompt recipes | explicit task request + supplied recipe manifest | `operating-prompt` provider |

The board projection is intentionally JSON rather than a Markdown parser in this package. `agent-kanban` keeps ownership of card syntax and policy; Recall receives only the stable projection supplied by the host. The same rule applies to future sources: their owner supplies a read-only provider or stable projection, while Recall composes returned facts.

## Standalone and governed task input

`TaskBriefParser` accepts two deliberately different entry shapes.

A standalone JSON brief is parsed directly into `TaskBrief`; inline CLI fields reach the same typed model through `InlineTaskBriefResolver`. Standalone input may have no approval status or durable Contract binding and must not be described as a governed Run merely because Recall can compile it.

A governed input has `kind=governed_recall_input` and contains:

```json
{
  "schema_version": "1.0",
  "kind": "governed_recall_input",
  "run_id": "run:TEST-42:0123456789abcdef",
  "contract": {
    "path": "../../contracts/TEST-42/contract.json",
    "sha256": "sha256:0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef",
    "revision": 2
  }
}
```

Before compilation, `TaskBriefParser`:

1. requires a non-empty `run_id`;
2. resolves the referenced Contract relative to the envelope;
3. verifies the exact SHA-256 of the Contract bytes;
4. parses the Contract into `TaskBrief` with a `GovernedRunBinding`;
5. requires `status=approved`;
6. requires the Contract revision to equal the envelope revision.

Changing only `run_id` changes governed lineage, not task semantics. A stale digest, stale revision, missing Contract, or non-approved Contract fails before provider composition.

See [Operating prompt recipes](operating-prompts.md#governed-input) for the host-facing governed flow.

## Provider contract and common metadata

Every `RecallProvider` declares a stable id, contract version, source paths, and whether it is required. For one normalized `TaskBrief` it returns a source digest plus `RecallFact` values. A fact has common fields such as:

- `id`, `type`, `authority`, `source_ref`, and `scope` to identify and inspect it;
- structured `payload`, not an opaque prompt fragment;
- `conflict_key`, `priority`, and `lifecycle` for deterministic precedence.

The compiler rejects duplicate provider ids. Provider manifests and source digests are retained in the canonical compilation snapshot so a later consumer can identify which input changed.

## Compilation pipeline

1. Normalize standalone inline/JSON task input or verify a governed Contract envelope into `TaskBrief`.
2. Sort registered providers by provider id and reject duplicate ids.
3. Precompute the optional `agent-map` provider. Exact `Class::method` targets produce deterministic `EditContextPlan` facts. Primary, contract, change-candidate, and verification files can extend effective scope; dependency and type-definition slices remain context-only.
4. Resolve map facts and derive the effective task scope before the remaining scoped providers run.
5. Invoke the remaining providers read-only. `task-context` receives the original task; other providers receive the effective task so map-derived change/verification files can participate in deterministic scope matching without rewriting the original task fact.
6. Resolve generic fact conflicts using explicit priority and authority precedence. Equal-precedence facts with different payloads block compilation; equal payloads are deduplicated.
7. Run typed guidance/constraint selection against the effective task, then verify selected project-local guidance validation entry points where a project root exists.
8. Serialize canonical `recall.bundle.json`, project `context_explain`, and deterministic renderings such as `system.md` and `validation-plan.md`. Rendering is not execution and L2 construction is left to the receiving agent/harness.

Provider relevance belongs at the provider boundary. For example, the project-document provider uses deterministic scope/tag/project-wide selection from an explicit Git-tracked manifest and a fixed excerpt limit; it neither scans an arbitrary documentation tree nor asks a model what appears relevant.

## Project capability evidence

`ProjectCapabilityRecallProvider` is intentionally bounded. It reads only supported repository metadata:

- `composer.json` when present, including `require.php`, exact Composer scripts, known development-tool packages, and direct dependencies explicitly named in the task description;
- an allow-list of PHPUnit, Codeception, PHPStan, Infection, php-cs-fixer, and Rector configuration files;
- `.github/workflows/*.yml` / `*.yaml` file names as CI navigation anchors.

Composer script definitions prove an exact repository command such as `composer ci`. Installed tool packages prove capability presence only; they do not prove that `vendor/bin/<tool>` is the repository-preferred invocation. CI files are anchors only and are not parsed into task policy by this provider.

## Context Explain is a projection, not another source

After compilation, `ContextExplainProjector` derives provenance from already-resolved facts and selection decisions. It does not ask a model to invent rationale and does not become a second source of truth.

The projection is stored as `selection-report.json.context_explain` and rendered into `system.md`. There is no separate `context_explain` artifact file.

For map-derived source slices, the relation may be discovered **through** `agent-map` while current repository source remains the source authority. Dependencies and type definitions retain context-only use semantics. Unknown future map roles fail closed to `context_only_until_verified`.

See [Context Explain](context-explain.md).

## Artifacts

A successful compile writes the core artifact set:

- `recall.bundle.json`: canonical task, effective scope, resolved facts, source snapshot, selected guidance/constraints/rejections, and fact decisions; the replay/audit anchor;
- `facts.json`: compact consumer view of resolved provider facts;
- `selection-report.json`: guidance/constraint selection plus effective scope and `context_explain`;
- `system.md`: deterministic briefing plus rendered edit context, context explanation, and selected operating-prompt material;
- `validation-plan.md`: deterministic validation briefing;
- `meta.json`: compilation metadata and hashes for immutable generated artifacts;
- `recall-log.draft.json`: editable close-out draft, intentionally excluded from immutable output hashes;
- `compilation-receipt.json`: successful-compilation receipt with `compilation_id`, canonical bundle digest, and operational timestamp; the timestamp is outside replay identity.

Conditional artifacts are:

- `verification-plan.json` and verifier-owned `verification-key.json` when a map index is supplied and the task has exactly one target; stale plan/key files are removed when the verification contract is not applicable;
- `feedback-assessment.draft.json` when non-empty feedback input is supplied.

A `RecallCompilationBlockedException` writes blocked `meta.json` and aborts before the normal successful artifact set. Invalid CLI/task input can fail earlier than that path.

## Relevance tags, independent of directory layout

Path `scope` is a prefix match against the task's files. That is precise, but relevant knowledge can cross directory boundaries and projects rarely share one directory layout.

`tags` is a second deterministic axis. A task and eligible guidance/document facts may declare flat project-defined labels. Relevance is established by exact path-scope overlap, exact tag overlap, or project-wide policy; the compiler does not perform semantic expansion such as guessing that `identity` means `ldap`.

Selection/exclusion reasoning remains explicit in the compiled report rather than being reconstructed by a model.

## Project document manifest

Projects opt in deliberately; the compiler never scans every Markdown file. Keep the manifest in Git beside project policy. Sources are relative to the manifest and `max_chars` is a hard growth bound.

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
    },
    {
      "id": "project.identity-handling",
      "type": "skill",
      "source": "docs/skills/identity-handling.md",
      "scope": ["src/Identity/"],
      "tags": ["identity", "ldap"],
      "max_chars": 1500
    }
  ]
}
```

Compile it explicitly with `--document-manifest path/to/recall-documents.json`. A document can be selected by path scope, exact task-tag overlap, or project-wide policy. Truncation is explicit in the fact payload and rendered briefing.

Conflicting facts at equal precedence do not receive an arbitrary lexical winner; they block until source policy resolves the conflict.

## Immutable outcome evidence, not Learning policy

`log-outcome` currently belongs to Recall and writes immutable event evidence under the Learning root:

- `history/recall-selections.jsonl`;
- `history/outcomes.jsonl`;
- `history/operating-prompt-outcomes.jsonl` when selected operating-prompt outcome rows exist.

The writer uses one lock, duplicate checks, redaction checks, and rollback-safe multi-file append. This is a real durable write surface owned by Recall.

What Recall does **not** own is automatic durable-guidance policy. Event counts or one task-local outcome do not by themselves promote, rewrite, weaken, or retire guidance. Those decisions belong to the Learning layer.

See [Guidance event history](guidance-events.md).

## Compatibility and host guidance

1. Existing inline and standalone JSON task-brief callers remain valid and must not be mislabeled as governed.
2. Governed hosts should pass the small `governed_recall_input` envelope and let `TaskBriefParser` verify the referenced approved Contract; hosts should not duplicate digest/revision/status validation.
3. Use `--map-root` when an `agent-map` index was produced under a different runtime root so source freshness can be checked against the active checkout.
4. Add project documents through a small explicit manifest rather than a global documentation dump.
5. Prefer the public `RecallCompiler` / `CompileRequest` / `CompileResult` API when a PHP host owns orchestration. Do not couple the host to `CompileCommand` internals or console prose.
6. Treat generated artifacts as evidence inputs. Their existence does not prove that an implementation agent consumed them, executed validation, passed review, or reached lifecycle close-out.
