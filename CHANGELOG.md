# Changelog

All notable changes to `voku/agent-recall-compiler` will be documented in this file.

The format follows Keep a Changelog, and this project uses semantic versioning where practical.

## [Unreleased]

## [0.13.11] - 2026-08-23

### Added

- Add bundled L2 `discovery-first` and `production-ready-handoff` operating recipes for repository re-grounding and fresh-agent execution handoff without inventing authority, commands, or scope.
- Add bundled L1 `execute-plan-with-blind-spot-check` for executing an existing authoritative plan only after a bounded evidence-backed self blind-spot pass; disproved assumptions may change only the smallest affected plan step, while missing Done When or executable verification evidence fails closed instead of being invented.

### Changed

- Governed Recall consumer guidance now routes ordinary lifecycle work through `agent-loop enter <task-id>` and `agent-loop finish <task-id>` plus the canonical next action instead of teaching a stale internal phase choreography.

### Validation

- PR #98 passed PHPUnit and PHPStan on PHP 8.3, 8.4, and 8.5 plus governed `agent-loop` context-explain dogfood for the discovery/handoff recipes.
- PR #102 passed PHPUnit and PHPStan on PHP 8.3, 8.4, and 8.5 plus governed `agent-loop` context-explain dogfood for `execute-plan-with-blind-spot-check` on exact head `eaa0fab86cf58c4a2f622dca490ad134b50f052f`.
- `agent-loop` PR #272 ran current Loop head `3c38e332c8f4ee87466fed67166ecff4af532f53` against exact Recall candidate `285ba3413a0a92c3dd34251c6bac3e6e574dc73a`; prompt-primitives dogfood run `32662042560` installed the candidate catalog and proved `workflow plan` accepts the new L1 recipe. The probe PR was then closed unmerged because no Loop product change or minimum-version bump was required.
## [0.13.10] - 2026-08-20

### Fixed

- Stop a file's navigation fact from absorbing the relation graph of every sibling that shares an inherited base class. A map file entry also lists the parents its own symbols extend, so an external base such as `PHPUnit\Framework\TestCase` is attributed to every file inheriting it; matching incoming relations against those shared ids grew with the square of the inheriting files. On a 311-file repository one test file's fact carried 88,179 relations (33.7 MB) instead of 949 (0.39 MB), and the compiled bundle reached 124 MB. Symbols listed under more than one file are now excluded from that matching, since a symbol listed by several files is declared by none of them. Files that declare their own symbols are unaffected.

### Validation

- PR #99 passed the PHP 8.3, 8.4, and 8.5 PHPUnit/PHPStan matrix plus governed `agent-loop` dogfood on exact head `c6ce6edb896499db8c2bd28eea1b0bc1e53da977`; no review threads or submitted review findings remained before merge.
## [0.13.9] - 2026-08-19

### Added

- Let `RecallFactsDocumentReader` own the standalone facts-document path through `path()` and `readFromOutputDirectory()`, so hosts no longer need to know the private `facts.json` filename.

### Validation

- PR #96 passed PHPUnit and PHPStan on PHP 8.3, 8.4, and 8.5 plus governed `agent-loop` dogfood; CodeRabbit reported no actionable review thread on the final head.
## [0.13.8] - 2026-08-19

### Added

- Add `RecallFactsDocumentReader` and `RecallFactsDocument` so hosts consuming a standalone compiled facts document can read its canonical bundle identity and typed facts without decoding Recall JSON themselves.

### Changed

- `CompiledRecallOutputReader` now delegates facts parsing to the same owner reader, leaving one implementation of the persisted facts schema inside Recall.
- Facts-document reading validates the real `1.0` document shape, raw lowercase 64-hex bundle digest, JSON-object root, and list-shaped fact collection while preserving open fact payload meaning for the host.

### Validation

- PR #94 passed PHPUnit and PHPStan on PHP 8.3, 8.4, and 8.5 plus the governed `agent-loop` context-explain dogfood.
- CodeRabbit reported no actionable review thread on the final PR head.
## [0.13.7] - 2026-08-19

### Added

- Add `CompiledRecallOutputReader`, `CompiledRecallOutput`, and `RecallFact` as the typed read side of compiled Recall output for lifecycle hosts.
- Expose semantic task/Contract binding, compilation identity, selected guidance/constraints, facts, task files, outcome-draft presence, bundle/facts readability, legacy task fallback, and owner-recorded output-integrity checks without requiring consumers to know Recall filenames or JSON keys.

### Changed

- Missing task identity matches no task; corrupt bundle/facts remain distinguishable from absent artifacts so hosts can preserve stale/incomplete recovery semantics.
- Recall now owns the legacy `current` task fallback and validation of its recorded output hashes, including rejecting unsafe recorded relative paths before hashing.
- Fact provenance and scope are preserved alongside the open payload; real object-shaped `selected_constraints` are exposed as their semantic IDs.

### Validation

- PR #92 passed CI on its final implementation head and resolved all three actionable CodeRabbit review threads.
- Consumer-driven Slice D work in `agent-loop` exercised the API against the existing lifecycle call sites and forced the reader to preserve real constraint, fact-provenance, missing/corrupt, task-fallback, and output-integrity semantics instead of simplifying fixtures around them.
## [0.13.6] - 2026-08-18

### Added

- Add `ReviewReportReader`, `ReviewReportArtifact`, and `ReviewReportPaths` so PHP lifecycle hosts can consume the current blind-spot report and its exact persisted SHA-256 identity without reconstructing Recall's file layout or JSON schema.

### Changed

- `ReviewReportWriter` now uses the same canonical path owner as the reader without changing the existing review artifact layout.
- Present-but-invalid, task-mismatched, binding-invalid, or internally inconsistent review reports fail explicitly; only an actually missing report returns `null`.
- Review artifact identity remains evidence only: Recall does not infer or record acknowledgement authority for lifecycle hosts.

### Validation

- PR #87 added focused regressions for missing state, exact persisted-byte identity, Contract/snapshot binding preservation, task mismatch, and status/finding consistency.
- GitHub Actions for the final PR head remained queued due runner congestion at merge time, so no green-CI claim is made for this release. The exact diff was reviewed before merge and the decoded report shape was made explicit for static analysis.
## [0.13.5] - 2026-08-18

### Added

- Add the bundled `test-driven-development` L2 operating recipe for evidence-backed RED/GREEN/REFACTOR work without turning test-first technique into workflow authority.
- Add typed `CompileRequest`, `RecallCompiler`, and `CompileResult` APIs so PHP lifecycle hosts can embed governed Recall compilation without reproducing CLI option spelling, provider composition, or artifact rules.

### Changed

- Embedded Recall compilation can suppress the human CLI success report while the standalone CLI keeps its existing output, allowing outer JSON or structured host protocols to remain valid.
- Document the PHP embedding boundary in the public docs and consumer skill: hosts own when compilation runs; Recall continues to own what compilation means.

### Validation

- PR #84 passed its exact-head CI before merge for the reusable TDD recipe.
- PR #85 passed CI run #380 after the public API regression suite and PHPStan correction, including the existing governed integration dogfood.
## [0.13.4] - 2026-08-17

### Changed

- `continue-until-done` now treats slice-by-slice self-confirmation as an internal continuation check only. Autonomous execution may continue while existing authority remains valid and evidence supports the next step, but it cannot satisfy human, owner, reviewer, accepted-risk, or approved-contract decisions by confirming itself.
- Crossing an external authority boundary now requires `HUMAN_DECISION_REQUIRED` / `BLOCKED`, while successful completion requires the configured Done condition to be satisfied by observed evidence.

### Validation

- PR #82 first reproduced the missing authority boundary with a test-only red regression, then passed PHPUnit and PHPStan on PHP 8.3, 8.4, and 8.5 plus the governed installed `agent-loop` context-explain dogfood.
- Exact release target `4c621e1e7ff511a7e1cc5c5aa70059b5aa532c5a` passed main-branch CI run #374 after merge. CodeRabbit was rate-limited for #82, so no external-review claim is made.
## [0.13.3] - 2026-08-17

### Added

- Add the bundled `todo-card-handoff` L2 recipe for self-contained durable task handoff to coding agents without chat, Session-private context, hidden reasoning, or prior-agent memory. The recipe requires repository anchors, verified/inferred separation, bounded next steps, observable acceptance, and the repository's existing task owner instead of inventing another backlog format.

### Changed

- Code-review and blind-spot L2 prompts now treat every LLM-produced statement as a candidate claim rather than repository truth. Material claims must be re-grounded against current authoritative artifacts or deterministic evidence before acting; detailed patches, scorecards, confident rationale, or review provenance do not prove that named classes, boundaries, bugs, or metrics exist.
- Blind-spot prompts now inherit the existing first-draft falsification lens and require material claims to carry an epistemic status plus concrete supporting or contradicting evidence. Review findings remain investigation candidates rather than instructions to modify code.

### Validation

- PR #79 added a real CLI compile regression for the bundled TODO handoff recipe.
- PR #80 first reproduced the missing model-output trust boundary with a red regression, then preserved the existing sub-1500-byte first-draft prompt budget after CI rejected an overlong draft.
- Exact release target `5934f4ecce271d00b2229e04bea4108e5c782bc4` passed main-branch PHPUnit and PHPStan on PHP 8.3, 8.4, and 8.5. PR #80 also passed the governed installed `agent-loop` context-explain dogfood. CodeRabbit was rate-limited for #80, so no external-review claim is made.
## [0.13.2] - 2026-08-16

### Fixed

- Deduplicate byte-identical navigation source references in compiled `system.md` while preserving first-seen order. The regression was independently reproduced by `voku/httpful#34` and `voku/phonetic-algorithms#6`.

### CI

- Stage the Recall candidate outside the nested consumer checkout before installed `agent-loop` dogfood, so the candidate under test cannot be confused with consumer-local state.

### Validation

- PR #70 passed PHPUnit and PHPStan on PHP 8.3, 8.4, and 8.5 plus the governed installed `agent-loop` context-explain dogfood. CodeRabbit reported no actionable review findings.

## [0.13.1] - 2026-08-16

### Fixed

- `regression-hunt` now treats `minimum_findings` as a bounded probe budget
  instead of a required defect count. Completed evidence-backed probes may
  return `CLEAN`, missing material evidence may return `BLOCKED`, and the
  recipe explicitly forbids manufacturing defects to satisfy the numeric floor.

### Validation

- PR #74 passed the package CI matrix and pins the CLEAN/BLOCKED and
  anti-defect-quota contract in `RegressionHuntPromptContractTest`.

## [0.13.0] - 2026-08-15

### Changed

- **Breaking:** blind-spot review reports may carry the exact Contract revision
  and implementation snapshot they reviewed, so governed consumers can reject
  a review after relevant implementation content changes without inventing a
  second review-evidence format.
- `review blindspots` accepts paired `--contract-revision` and
  `--implementation-snapshot sha256:<digest>` options. Standalone unbound review
  remains supported; repository snapshot semantics stay with the orchestrator.

## [0.12.4] - 2026-08-14

### Changed

- Allow `voku/agent-map` `^0.8.0` in addition to the existing 0.5, 0.6, and
  0.7 lines. This is a compatibility-only release: Recall providers, facts,
  bundle digests, prompt rendering, and outcome semantics are unchanged.

### Validation

- The package's normal Composer install and PHPUnit/PHPStan matrix must resolve
  against the published `agent-map 0.8.0` line before this compatibility release
  is tagged; no path repository or development-branch alias is used.

## [0.12.3] - 2026-08-14

### Changed

- The opt-in `prompt guidance-gaps` diagnostic now classifies each material
  authority failure as `AUTHORITY_MISSING`, `AUTHORITY_NOT_SURFACED`,
  `AUTHORITY_STALE`, `AUTHORITY_CONFLICTING`, or `AUTHORITY_INCOMPLETE`.
- `AUTHORITY_NOT_SURFACED` is evidence-bound: agents may use it only when an
  applicable authority is proven to exist but was absent from usable context.
  Its remediation points at manifest, scope, retrieval, installation, or
  routing of that existing authority instead of manufacturing replacement docs.

### Validation

- This distinction came from real `agent-loop#113` self-shape dogfood: the
  self-shaping lifecycle document already existed, but the consuming project's
  Recall document manifest did not register it. Registering the existing
  authority changed the real governed Recall bundle without rewriting the doc.
- PR #60 passed PHPUnit and PHPStan on PHP 8.3, 8.4, and 8.5 plus the governed
  `agent-loop` consumer, AccessLint and CodeRabbit. The exact merged commit
  passed main-branch PHP/PHPStan CI before this release candidate was prepared.

## [0.12.2] - 2026-08-14

### Added

- Added the explicit `prompt guidance-gaps` L2 diagnostic for spec-driven,
  human-in-the-loop implementation sessions. The generated project-specific
  prompt keeps a running `implementation-notes.html` journal that separates
  design decisions, deviations, tradeoffs and open questions from actual
  guidance gaps where the expected spec, docs, skill, workflow, tool contract,
  code or tests did not provide usable authority.
- Material ambiguity that would change approved goals, acceptance criteria,
  scope/non-goals, public contracts, security/safety boundaries or destructive
  behavior now has an explicit `HUMAN_DECISION_REQUIRED` / `BLOCKED` boundary
  instead of inviting the coding agent to guess.

### Changed

- The journal is task-local working evidence and is not committed unless the
  approved task or harness explicitly requires it. The technique remains opt-in:
  it does not add a workflow stage, mutate docs or skills, manufacture backlog,
  or promote notes to durable Learning automatically.

### Validation

- Dogfooding the new technique against its own implementation exposed two real
  guidance gaps before release: the journal lifetime was initially unspecified,
  then the builder, documentation and installed skill disagreed about that rule
  and the opt-in boundary. Regressions now bind those agent-facing surfaces.
- PR #57 passed PHPUnit and PHPStan on PHP 8.3, 8.4 and 8.5 plus the governed
  agent-loop consumer dogfood, AccessLint and CodeRabbit; the exact merged commit
  passed main-branch CI before this release candidate was prepared.

## [0.12.1] - 2026-08-14

### Changed

- The canonical `agent-recall-consumer` skill now teaches the released 0.12
  outcome-honesty contract: unedited `unknown` rows are placeholders, explicit
  `unknown` requires a reason, and a caller without evidence must declare
  `guidance_outcomes_withheld_reason` rather than fabricating `not_used` or `
  irrelevant`. Selection events retain `outcome_withheld_reason`.

### Validation

- The bundled-skill regression binds each serialized field to its runtime owner
  and pins the honesty rules; PHP 8.3/8.4/8.5 CI and the governed agent-loop
  consumer dogfood are green.

## [0.12.0] - 2026-08-14

### Changed

- **Breaking:** `log-outcome` no longer accepts an unedited `recall-log.draft.json` as guidance feedback. `compile` pre-fills every selected row with `outcome: unknown` and no comment for a session to complete afterwards; logging that verbatim meant the component that selected the guidance also graded it. An `unknown` outcome now requires a comment saying why the guidance could not be judged.
- Guidance outcomes carry the coherence rules operating-prompt outcomes already enforced: `helpful`, `irrelevant` and `harmful` require a justifying comment, and `helpful` and `harmful` cannot be recorded with `applied: false`.
- A selected guidance may be left unjudged when the draft states `guidance_outcomes_withheld_reason`. Selection is a fact about the compiler and usefulness is a fact about the session; a caller with no evidence was previously forced to invent a bucket, and both `not_used` and `irrelevant` push a rule towards retirement in `agent-learning`'s staleness policies. Silent omission is still refused, so an accidental drop does not look like a deliberate one.

### Added

- Recall selection events record `outcome_withheld_reason` for the selections a declared withholding explains, so a downstream gate can distinguish a declared absence from a dropped one after the draft is pruned. The field is additive and absent on existing records.

### Validation

- PHPUnit 170 tests / 978 assertions and PHPStan clean on PHP 8.3, 8.4 and 8.5, plus the governed `agent-loop` context-explain dogfood, before merging PR #50.
- Exercised end to end through a governed `agent-loop` run, which is where the consuming `workflow close` gate was found to demand a judgement per selection and had to learn to accept a declared withholding.

## [0.11.6] - 2026-08-14

### Changed

- Align the canonical `agent-recall-consumer` skill with the live Recall CLI: compact `.agent-loop` defaults, current compile/outcome/review/reflection commands, and the `agent-loop` project-layout boundary are now documented from the owning package.
- Retired project-specific Learning/Recall paths are no longer taught by the shipped skill.

### Validation

- The bundled-skill regression now checks its documented top-level commands against the real `Cli` dispatch table and rejects retired roots.
- PR #48 passed PHPUnit and PHPStan on PHP 8.3, 8.4, and 8.5 plus the governed `agent-loop` context-explain dogfood before merge.
## [0.11.5] - 2026-08-14

### Changed

- Recall-specific coding instructions and reusable operating-prompt assets now
  live with `agent-recall-compiler`: the first-party catalog ships beside
  `skills/agent-recall-consumer/SKILL.md` instead of requiring a separately
  versioned generic skill repository. Callers still select every recipe and
  provide its arguments explicitly; bundling the catalog adds no hidden prompt
  selection or defaults.
- `docs/operating-prompts.md` now makes the ownership boundary explicit:
  tool-neutral engineering principles may stay with their generic owner, while
  Recall commands, review primitives, manifest schema, and Recall-consumed
  first-party recipe assets are reviewed, tested, and released with this tool.

### Validation

- Added `BundledOperatingPromptCatalogTest`, which compiles the shipped
  `adversarial-review` recipe through the real CLI and proves the falsification
  contract still permits `CLEAN` after evidence-backed probes find no defect.
- PR #44 passed the repository CI matrix before merge; the 0.11.5 release
  candidate reruns the same package gates with the ownership documentation.

## [0.11.4] - 2026-08-14

### Added

- Added a context-light `review first-draft` primitive for manual or automated
  falsification-oriented review without requiring task/run state. The library and
  CLI surface deliberately allows `CLEAN` only after concrete attempts to break
  the implementation find no evidence-backed defect, and rejects unexpected CLI
  arguments instead of silently ignoring them.
- Artifact-backed `review code <task-id>` prompts now inherit the same first-draft
  falsification lens while keeping their existing project evidence and dominant
  installed review-lens routing.

### Changed

- L2 operational prompt construction now preserves approved acceptance criteria,
  non-goals, and scope explicitly; selected context never grants edit permission.
- Missing required verification remains `UNKNOWN` or `BLOCKED`. Model confidence,
  prior rationale, reviewer consensus, prompt construction, and unexecuted commands
  are not verification, and changing an approved contract requires a separate
  approved re-plan rather than weakening requirements inside the generated L1.

### Validation

- PHPUnit and PHPStan passed on PHP 8.3, 8.4, and 8.5 on the exact feature head.
- The governed `agent-loop` context-explain dogfood passed with the candidate, and
  external review found and closed the unapproved contract-change escape hatch
  before merge.

## [0.11.3] - 2026-08-13

### Fixed

- Compile-time `TaskBrief` augmentation now preserves the complete governed task
  semantics when CLI targets or inline operating prompts are added. In particular,
  the governed Run binding and approved acceptance criteria no longer disappear
  while the requested target/prompt collection is extended.

### Validation

- Added public `compile` CLI regressions for both `--target` augmentation and
  inline operating-prompt augmentation from a governed Contract, asserting the
  canonical task-context fact retains exact acceptance criteria and Run lineage.
- PHPUnit and PHPStan passed on PHP 8.3, 8.4, and 8.5, together with the governed
  agent-loop context-explain dogfood, on the exact fix candidate before merge.

## [0.11.2] - 2026-08-13

### Added

- Added approved task `acceptance_criteria` as an optional governed task input.
  Criteria are carried through direct and governed Contract parsing, canonical
  task-context facts, effective task scope, and rendered `system.md` briefings.
- Acceptance criteria are rendered explicitly as required outcomes from the
  approved task Contract, **not** as evidence that those outcomes are satisfied.
  Missing criteria remain backward compatible as an empty list.

### Validation

- Added focused regressions for direct and governed inputs, malformed criteria,
  task-context facts, briefing rendering, and effective-scope preservation.
- PHP 8.3, 8.4, and 8.5 CI plus the governed `agent-loop` dogfood passed on the
  exact release candidate before merge.

## [0.11.1] - 2026-08-13

### Added

- Added context-light future-work reflection prompts through
  `prompt future-work --scope project|task`. Project scope asks for one
  highest-leverage future investment without manufacturing backlog work; task
  scope asks what additional depth may have been missed and emits
  `RETURN_TO_REVIEW` when deeper scrutiny reveals that the task was not actually
  complete.
- Added deterministic library support via `FutureWorkPromptBuilder` and
  `FutureWorkScope`. Reflection deliberately does not inject Recall artifacts or
  reuse the operational L2 `Goal / Context / Constraints / Verification / Done
  When` execution-contract shape.

### Validation

- Added focused PHPUnit coverage for both scopes, context-light rendering,
  invalid-scope failure, and the public CLI path. The release candidate passed
  the repository PHPUnit/PHPStan CI matrix before merge.

## [0.11.0] - 2026-08-12

### Added

- **Breaking:** governed Recall input now requires a non-empty `run_id` and the
  exact Contract revision, source reference and SHA-256 it was compiled for.
  `GovernedRunBinding` carries that lineage into the compiled briefing, so a
  bundle can be tied to the one Run and Contract revision it was built for
  instead of being attributed by filename and timestamp.

  Consumers that passed an ungoverned task brief are unaffected; a brief that
  declares itself governed must now prove which Run it belongs to rather than
  leaving the binding implicit.

## [0.10.2] - 2026-08-11

### Changed

- Allow `voku/agent-map` `^0.7.0` in addition to the existing 0.5 and 0.6 lines.
  This is a compatibility-only release: recall semantics, provider contracts, facts,
  bundle digests, and rendered prompts are unchanged.

### Validation

- Release remains gated on PHPUnit and PHPStan across PHP 8.3, 8.4, and 8.5.
- The package's normal Composer install resolves against the published `agent-map 0.7.0`
  line, proving the new constraint rather than testing an unreleased path dependency.

## [0.10.0] - 2026-08-09

### Added

- Added versioned operating-prompt manifests and typed prompt requests. Recipes
  explicitly declare `level: 1|2`; selected prompt IDs, arguments, rendered
  content, source references, and template digests are carried in replayable
  recall facts and bundle evidence.
- L2 recipes now compile into an explicit project-specific L1 construction
  contract with exactly five ordered sections: `Goal`, `Context`, `Constraints`,
  `Verification`, and `Done When`. `Verification` defines how reality is measured;
  `Done When` defines which observed result permits the task to stop.
- Added bounded deterministic project-capability discovery from supported
  repository evidence: PHP/Composer metadata, exact Composer scripts, known test,
  static-analysis, mutation and formatting tool packages/configuration, plus CI
  workflow anchors. Tool presence never invents an invocation command; unresolved
  commands remain `UNKNOWN`.
- Added per-recipe outcome evidence in
  `history/operating-prompt-outcomes.jsonl`. Selected recipes can record
  `helpful`, `irrelevant`, `harmful`, `not_used`, or `unknown` outcomes with
  argument digest, application state, evidence, actor, task, compilation, commit,
  and time. Future recall facts expose aggregate prior outcome counts without
  automatically mutating recipe policy.
- Added `operating_prompt_outcomes` rows to the outcome draft so recipe use can be
  evaluated separately from normal guidance use.

### Changed

- Generated code-review prompts route through one dominant installed engineering
  review lens with at most one evidence-backed required handoff instead of
  embedding a generic review ritual in recall.
- Repository `MEMORY.md` and other project-root evidence resolve through the
  configured recall project root, with boundary checks that prevent path escape.
- Canonical guidance handoff now prefers the exact physical canonical source when
  an applied proposal has transferred authority there, avoiding duplicate active
  proposal wording in the same compile.
- L2 construction preserves hard numeric floors and stop conditions, prefers
  exact files/symbols/callers/tests/commands from recall, removes hedge language,
  never invents repository policy, and stops after L1 construction rather than
  implementing during the meta-prompt pass.

### Fixed

- Prompt arguments are restricted to deterministic `bool|int|string` values;
  float formatting cannot silently change replay identity across runtimes.
- Literal `{{...}}` text inside supplied argument values is preserved instead of
  being mistaken for an unresolved manifest placeholder.
- Empty prompt selections do not change legacy task-context or bundle shapes and
  therefore do not cause digest drift for tasks that do not use operating prompts.
- Project-capability facts are omitted when no supported evidence exists rather
  than adding an empty provider that changes otherwise identical compilations.

### Documentation

- `docs/operating-prompts.md` defines the L2 -> project-specific L1 flow, exact
  five-section contract, manifest schema, project-capability evidence, recipe
  outcome history, and the boundary that reusable prompt semantics belong in the
  owning catalog while project facts stay in recall.

## [0.9.2] - 2026-08-06

### Fixed

- The binary resolved its autoloader by preferring the package's own `vendor/`
  directory. When one is present next to an installed copy - a path repository, a
  mirrored checkout, a stale local install - that autoloader wins and silently
  loads *its* dependencies instead of the project's. Found by a release-set smoke
  test that reported `Undefined property Session::$ephemeral` against an
  installed version that plainly had it. The outer autoloader is now tried first.

## [0.9.1] - 2026-08-05

### Changed

- Documented the `--document-manifest` format in the README: field meanings,
  `max_chars` bounds and truncation marking, and the three selection paths
  (path-scope overlap, tag overlap, project-wide). The project-wide form -
  `scope` empty, `["/"]`, or `["*"]` - was supported but undocumented, so
  environment-level guidance that has no path scope by nature had no obvious way
  into a briefing.

## [0.9.0] - 2026-08-05

### Added

- `compile --map-search-index PATH` (plus `--map-search-limit N`, default 8) turns
  agent-map 0.4.0's derived hybrid-search index into ranked candidates for a task
  that has a description but no exact `--target` yet. The candidates travel as a
  `navigation_candidates` fact (`map.search.candidates`) and render in `system.md`
  under *Candidate Navigation (ranked, unverified)*.
- Every reason the search cannot produce candidates is emitted as a
  `map.search.status` fact - `missing`, `stale`, `unavailable`, `skipped` - and
  rendered in the briefing. An absent section would be indistinguishable from
  "the search found nothing", which is a different answer.

### Changed

- Candidates are deliberately weaker than the existing facts and are labelled as
  such: they are ranked over a derived index, not resolved through the canonical
  map. They never widen the effective task scope and therefore never select
  path-scoped guidance, and the briefing marks them **INFERRED**.
- A search index whose `map_snapshot` does not match the map's analysis
  fingerprint is refused rather than used, naming both snapshots.
- The provider contract version stays `2.0` when no search index is configured,
  so a compilation that does not use the feature keeps its previous bundle digest.

## [0.8.1] - 2026-08-05

### Changed

- Requires `voku/agent-map` `^0.4.0`. The compiler keeps using the same
  `Index` and `Context` API, but the constraint is what lets a consumer install
  the 0.4.x line at all - the derived hybrid-search index
  (`agent-map search-index`, `.agent-map/search.sqlite`) and the parallel chunk
  extraction it brings are unreachable while any package in the tree still pins
  `^0.3.0`.

## [0.8.0] - 2026-08-05

### Added

- Deterministic verification plans. When a compilation resolves a map target,
  `compile` now emits two new artifacts next to the existing recall output:
  - `verification-plan.json` - the public contract: knowledge probes with their
    question, answer format and evidence ids, the evidence checklist, the
    objective gates and which of them are required, the required evidence
    types, the map digest and analysis fingerprint the plan was derived from,
    and the omitted probe candidates with the reason each was rejected.
  - `verification-key.json` - the verifier-owned answer key: canonical probe
    answers bound to `plan_sha256`, `target` and `map_digest`.
  The key is deliberately not referenced from `system.md`; the prompt receives
  only the questions.
- `system.md` gains a "Repository-Knowledge Verification" section listing the
  probes, and `validation-plan.md` gains a "Declared Verification Contract"
  section listing the checklist, the objective gates, and the fixed scoring
  rule a consumer must apply (`objective_gate != passed -> gated_evidence_score
  = 0`).
- `meta.json` gains `verification_plan_sha256`, `verification_key_sha256` and
  `verification_generator`, and both artifacts are included in `output_hashes`,
  so a stale key cannot survive a map change unnoticed.

### Changed

- A compilation without an eligible map target removes any
  `verification-plan.json` / `verification-key.json` left over from an earlier
  run instead of leaving stale artifacts in the output directory.

## [0.7.2] - 2026-08-04

### Changed

- Requires `voku/agent-map` `^0.3.0`. That release moves each top-level index
  section onto its own line (still ordinary JSON, and `IndexReader` reads both
  layouts), adds `refresh`/`--merge` for incremental index updates, and stops
  the symbol extractor from executing host code while parsing. Recall only
  reads indexes, so nothing in this package changed behaviourally.

## [0.7.1] - 2026-08-03

### Added

- Added repeatable `--edit-focus` literals for target-aware compilation. They
  instruct `agent-map` to render bounded primary-source windows around local
  matches, retaining the full target if none match.
- Requires `voku/agent-map` 0.2.1 or newer for the focus-aware context policy.

## [0.7.0] - 2026-08-03

### Added

- Added repeatable exact `Class::method` task targets through task briefs and
  inline `--target`, backed by `voku/agent-map` 0.2 JSON or TOON indexes.
- Added deterministic rendering of primary methods, contracts, direct callers,
  tests, dependencies, type definitions, blind spots, omissions, exact source
  ranges, and source hashes into the compiled briefing.
- Added explicit map-derived effective scope so path-scoped guidance and
  project documents can match primary/contract/caller/test files while
  dependency-only context does not silently become an intended edit.

### Changed

- `MapRecallProvider` now delegates decoding, SHA-256 freshness checks, target
  resolution, relation traversal, and source materialization to `agent-map`
  instead of parsing the old JSON/SHA-1 schema itself.
- Validation-plan filtering uses the effective task scope; canonical bundles and
  selection reports retain both the original task scope and its derivation.

## [0.6.9] - 2026-08-02

### Fixed

- Rejected duplicate proposals with the same non-empty `pattern_key` as selected
  active guidance no longer block recall compilation merely because they share a
  target. They remain selected as historical warnings; only a rejected *different*
  pattern targeting the same guidance surface is a contradiction.

## [0.6.8] - 2026-08-02

### Added

- Approved work-brief behavior anchors are parsed, carried into task-context
  facts, and rendered in L2 prompts so agents retain the concrete behavior
  that must remain unchanged.
- L2 prompts now require material conclusions to distinguish verified,
  inferred, assumed, blocked, and contradicted claims. Peer feedback remains
  untrusted until corroborated by repository, history, tests, or safe runtime
  evidence.

## [0.6.7] - 2026-07-28

### Fixed

- Accept active `phpcs` constraint manifests, validate either `phpcs` or
  `php_codesniffer` commands, and label the generated validation-plan section
  as `PHPCS`.

## [0.6.6] - 2026-07-23

### Added

- Added an optional `tags` relevance axis, orthogonal to path `scope`. Task
  briefs, guidance/constraint/rejection/retirement records, and scoped
  document manifest entries may declare `tags`; a fact is selected when its
  path scope overlaps the task's files **or** it shares at least one tag with
  the task. This lets a project register cross-cutting knowledge (e.g. an
  LDAP learning) by domain/system/capability instead of directory prefix, so
  selection works the same way regardless of how a project's codebase is
  laid out. Purely additive: briefs and manifests without `tags` behave
  exactly as before.
- Added `SelectionReason::TAG_OVERLAP` to distinguish a tag-only match from a
  path `scope_overlap` or `global` match in `selection-report.json`.
- Added inline `--tag LABEL` (repeatable) to `compile` for ad hoc task input.

## [0.6.5] - 2026-07-22

### Added

- Added internal `RecallProvider` contracts and a canonical, replayable
  `recall.bundle.json` snapshot. Providers now contribute source digests and
  structured facts for task context, repository memory, approved learning and
  hard constraints, optional map indices, typed Kanban projections, and
  explicitly registered project Skills/ADRs.
- Added `facts.json`, `selection-report.json`, and
  `compilation-receipt.json`. The first two are deterministic, hash-covered
  consumer artifacts; the receipt is operational timestamp metadata and is
  deliberately outside the replay identity.
- Added `--map-root`, `--kanban-context`, and repeatable
  `--document-manifest` compile options. Project documents are selected only
  by explicit scope/prefix rules and fixed excerpt limits.

### Changed

- Approved `agent-session` work briefs (`task_id`, `goal`, `scope`,
  `non_goals`, validation, status, revision) are first-class task inputs.
  Rendered L2 prompts are deterministic views of the canonical bundle; the
  compiler never executes them or calls a model.
- Generic fact conflicts resolve only through explicit priority and documented
  authority precedence. Equal-precedence facts with different payloads block
  compilation instead of silently choosing one.
- Validation plans retain the approved work-brief commands and explicitly
  separate legacy constraint commands that name task-external PHP files.

### Fixed

- A Docker-built `agent-map` index can now be verified against an explicitly
  supplied host root. Missing or stale task files are emitted as explicit
  navigation-status facts rather than being silently rebuilt or trusted.

## [0.6.4] - 2026-07-20

### Added

- Contradiction detection now also covers retired proposals, not just rejected ones. `RecallRepository::loadRetiredProposals()` returns full `RecallRetirement` records (target, reason, scope, action) instead of bare IDs, and `RecallDecisionEngine::decide()` blocks compilation when newly selected guidance targets a rule that was retired for cause in the same task scope, surfacing the original retirement reason. `loadRetiredProposalIds()` is kept for callers that only need the ID list.

## [0.6.3] - 2026-07-20

### Fixed

- Only treat a rejected proposal as contradictory when its scope also matches the current task; unrelated rejected proposals may share broad targets such as `MEMORY.md`.

## [0.6.2] - 2026-07-16

### Fixed

- `review blindspots`/`review code` now write their report/prompt files under a `reviews/` subfolder of the same `--output-dir` they read compiled recall inputs from, instead of a hardcoded, workspace-root-relative `.agent-recall/reviews/` that ignored `--output-dir` entirely. A project that points `--output-dir` (or a downstream tool's recall-root config) somewhere other than `.agent-recall/current` now gets one consistent output tree for compile+review instead of review output always landing at the same fixed path regardless of configuration. The default output dir (`.agent-recall/current` when `--output-dir` is omitted) now produces reports under `.agent-recall/current/reviews/` rather than `.agent-recall/reviews/`.

## [0.6.1] - 2026-07-14

### Fixed

- Recall compilation no longer turns a historical `irrelevant` outcome into a warning for a different task. The outcome remains in the immutable history and in projected usage statistics; only prior `harmful` guidance is surfaced as a current-task warning.
- `recall-log.draft.json` and `feedback-assessment.draft.json` are no longer recorded in a compiled `meta.json`'s `output_hashes`. Both files are designed to be hand-edited after compile (`guidance_outcomes`, review verdicts), so including them in that tamper-evidence hash set made every correctly-completed task permanently fail a downstream verifier's staleness check.

## [0.6.0] - 2026-06-29

### Added

- Added a deterministic `review` CLI workflow with `blindspots` and `code` subcommands. The workflow writes audit-ready Markdown/JSON review reports plus L2 blind-spot and code-review prompts under `.agent-recall/reviews/` without invoking an LLM.
- Added `docs/agent-loop-review-follow-up-prompt.md` to carry the dogfooded review workflow and safety corrections into `voku/agent-loop`.
- Added command-level PHPUnit coverage for option parsing and review workflow coverage for task-id validation, report contents, CLI dispatch, malformed meta handling, session matching, and generated prompt formatting.

### Changed

- Refactored the monolithic CLI implementation into command classes for `compile` and `log-outcome`, plus shared parsed-option value objects, so existing commands use the same command-oriented architecture as the new review workflow.

### Fixed

- Compile artifact writes now fail fast when `file_put_contents()` fails instead of printing success with missing or partial output.
- Long CLI options that require values now reject bare `--name` tokens instead of silently treating them as empty strings.
- Review artifact collection now honors `--root`, rejects traversal in output paths, bounds recursive session reads, and avoids pulling unrelated session notes through substring task-id matches.


## [0.5.2] - 2026-06-23

### Fixed

- Retiring a `voku/agent-learning` proposal (0.7.0 `ProposalStatus::RETIRED`) removed it from
  `loadActiveGuidance()` as intended, but `RecallDecisionEngine::decide()`'s "unknown rule ID" check
  builds its known-ID set only from active guidance, constraints, and rejections. A historical
  `outcomes.jsonl` event recorded while the proposal was still `applied` legitimately still
  references its ID, so every later `recall compile` for any task BLOCKED with `Conflict: outcome
  references unknown rule ID '<id>'` the moment that proposal was retired, even though nothing about
  the requested task was wrong. Found by dogfooding against a downstream repository immediately
  after retiring a proposal there for the first time.
- Added `RecallRepository::loadRetiredProposalIds()` (reads `proposals/retired/*.json`, IDs only) and
  a new `decide(..., array $retiredProposalIds = [])` parameter so retired IDs stay known to the
  conflict check without ever being selectable as guidance. `Cli.php`'s `compile` command now loads
  and passes them through. Default value keeps the signature change backward compatible for direct
  `RecallDecisionEngine::decide()` callers that omit the new argument.

## [0.5.1] - 2026-06-23

### Added

- Added `RecallRepository::loadRetiredProposalIds()` (reads `proposals/retired/*.json`, IDs only) and
  a new `decide(..., array $retiredProposalIds = [])` parameter so retired IDs stay known to the
  conflict check without ever being selectable as guidance. `Cli.php`'s `compile` command now loads
  and passes them through. Default value keeps the signature change backward compatible for direct
  `RecallDecisionEngine::decide()` callers that omit the new argument.

## [0.5.0] - 2026-06-20

### Added

- feat: add untrusted peer feedback handling and compilation conflict resolution

## [0.4.1] - 2026-06-19

### Fixed

- `OutcomeLogger::log()` could throw a spurious `type mismatch` error for any
  `log-outcome` draft that evaluated a legacy `target_type: "file"` guidance
  entry (e.g. a MEMORY.md-targeting proposal), even when that entry was not
  selected. `RecallDecisionEngine` projected `"file"` onto `GuidanceType::MEMORY`
  at compile time, but `OutcomeLogger::knownGuidanceTypesById()` re-derived the
  type independently and fell back to `GuidanceType::SKILL`, disagreeing with the
  compiled draft.
- Centralized guidance-type derivation in `GuidanceType::fromTargetType()` so
  `RecallDecisionEngine`, `OutcomeLogger`, and `RecallPromptBuilder` can no
  longer drift apart on this mapping.

## [0.4.0] - 2026-06-19

### Changed

- Refactor recall compiler internals
- Fix duplicate event id sequencing
- Tighten inline task resolver types

## [0.3.2] - 2026-06-18

### Changed

- improve suffix validation (without 'ext-ctype')
- simplify regex in secret assignment detection

## [0.3.1] - 2026-06-18

### Changed

- fix: handle mkdir failure when creating output directory

## [0.3.0] - 2026-06-18

### Added

- Add stable compilation IDs to compile output, with optional `--compilation-id` support and generated IDs when omitted.
- Add evaluated-guidance tracking with typed guidance types, selection reasons, and exclusion reasons.
- Add immutable recall-selection and per-guidance outcome event models.
- Add transactional event history appends to `history/recall-selections.jsonl` and `history/outcomes.jsonl` during governed `log-outcome` close-out.
- Add duplicate protection for event IDs and `compilation_id + guidance_id` pairs.
- Add redaction checks for generated event records.
- Add `meta.json` fields with schema version, compilation ID, task files, evaluated guidance, selection/exclusion reasons, selected constraint reasons, and output hashes.
- Add `recall-log.draft.json` editable guidance outcome rows and schema documentation.
- Add regression coverage for supplied/generated compilation IDs, evaluated-guidance ordering, event appends, duplicate retry safety, invalid outcome references, and redaction.

### Changed

- Extend legacy outcome-stat handling so new per-guidance outcome events and older aggregate outcome records can coexist.
- Treat legacy proposal `target_type=file` records as memory guidance when projecting evaluated guidance events.
- Update consumer guidance to distinguish eligible, selected, applied, and helpful signals.

## [0.2.0] - 2026-06-18

### Added

- Add outcome statistics for selected guidance and constraints, separating `selected_count`, `helpful_count`, `irrelevant_count`, `harmful_count`, and `violation_detected_count`.
- Include outcome signal counts in generated `system.md` and `meta.json` outputs when prior outcome data exists.
- Add tests proving selection is tracked separately from usefulness.

### Changed

- Generate `recall-log.draft.json` with empty usefulness buckets instead of pre-marking selected guidance as helpful.
- Require every selected rule in a logged outcome to be classified exactly once as `helpful`, `irrelevant`, or `harmful`.
- Reject outcome feedback for rules that were not selected for the session.
- Update consumer guidance to state that prompt selection is exposure only, not evidence of usefulness.

## [0.1.1] - 2026-06-14

- Emit selected hard constraints directly in `system.md` with a concrete execution contract and required validation commands.
- Read `active_constraints_dir` from learning-root `config.json` when loading active hard-constraint manifests.
- Expand root auto-discovery to the same common learning-root directories supported by `voku/agent-learning`.

## [0.1.0] - 2026-06-14

### Added

- Add constraint manifest parsing for active hard constraints.
- Select active constraints deterministically by scope overlap and include global constraints for `*` or `/` scopes.
- Include selected constraint IDs, rule identifiers, validation commands, and source proposal provenance in compiler outputs.
- Support generated-rule outcome result types such as `violation_detected`, `false_positive`, `rule_suppressed`, and `rule_disabled`.

### Changed

- Clarify README behavior for compile-blocking conflicts, rejected-guidance contradictions, schema validation, invalid active rules, missing constraint validation commands, and invalid outcome references.
- Make `validation-plan.md` authoritative for selected constraints by listing required commands and rule identifiers.

## [0.0.2] - 2026-06-12

### Added

- Add strict compilation-blocking checks (RuntimeExceptions) for:
  - Selected active rules with status other than `approved` or `applied`.
  - Multiple active rules targeting the same codebase location (conflict checks).
  - Target contradictions matching any known rejected proposals in the repository history.
  - Rules of type `constraint` that do not define any validation commands.
  - Outcome records referencing unknown rule IDs.
- Add `schema_version` validation check (`"1.0"`) to task briefs, guidance files, and outcome logs.
- Add `selected` and `applied` rule fields in generated outcome logs to separate prompt selection from actual rule utilization.

## [0.0.1] - 2026-06-12

### Added

- Initial release of L2 Meta-Prompt Compiler and Briefing Manager for coding agents.
- Deterministic scope matching for MEMORY.md and specific active skills/constraints.
- Rejection warnings to notify the agent of previously proposed and rejected designs.
- Outcome-driven warnings to flag rules marked as `HARMFUL` or `IRRELEVANT` in past sessions.
- Dynamic validation plan compiler that lists verification tests for loaded active rules.
- Draft outcome log generation to close the feedback loop.
