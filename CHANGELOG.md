# Changelog

All notable changes to `voku/agent-recall-compiler` will be documented in this file.

The format follows Keep a Changelog, and this project uses semantic versioning where practical.

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
- Added review domain objects and prompt builders for blind-spot findings, severity/status projection, report writing, bounded artifact collection, safe task-file inclusion from `meta.json`, boundary-aware session artifact matching, and Markdown fence-safe prompt rendering.
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
  the requested task was wrong. Found by dogfooding against a real repository (IT-Portal) immediately
  after retiring a proposal there for the first time.
- Added `RecallRepository::loadRetiredProposalIds()` (reads `proposals/retired/*.json`, IDs only) and
  a new `decide(..., array $retiredProposalIds = [])` parameter so retired IDs stay known to the
  conflict check without ever being selectable as guidance. `Cli.php`'s `compile` command now loads
  and passes them through. Default value keeps the signature change backward compatible for direct
  `RecallDecisionEngine::decide()` callers that omit the new argument.

## [0.5.1] - 2026-06-23

### Added

- Added `RecallCompilerTest::testLoadActiveGuidanceNeverReturnsRetiredProposals()`, a regression
  test locking in that `RecallRepository::loadActiveGuidance()` only ever scans `proposals/approved/`
  and `proposals/applied/`. `voku/agent-learning` 0.7.0 added a `retired` `ProposalStatus` for
  proposals whose durable change is already fully captured in its target skill/doc/memory home; this
  package needed no behavior change to support it (a retired proposal already lived in a directory
  this package never reads), but the invariant was previously only documented, not tested.

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
  type independently and fell back to `GuidanceType::SKILL`, disagreeing with
  the compiled draft.
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
- Add `meta.json` fields for schema version, compilation ID, task files, evaluated guidance, selection/exclusion reasons, selected constraint reasons, and output hashes.
- Add one editable `guidance_outcomes` row per selected guidance item in `recall-log.draft.json`, defaulting to `applied=false` and `outcome=unknown`.
- Add schema documentation and an end-to-end fixture for compile, selected guidance, completed feedback, and immutable event histories.
- Add regression coverage for supplied and generated compilation IDs, evaluated-guidance ordering, selected guidance draft rows, event appends, duplicate retry safety, unknown schemas, non-selected applied guidance rejection, and secret-like value redaction.

### Changed

- Extend legacy outcome-stat handling so new per-guidance outcome events and older aggregate outcome records can coexist.
- Treat legacy proposal `target_type=file` records as memory guidance when projecting evaluated guidance events.
- Update README and bundled skills to distinguish eligible, selected, applied, and helpful signals and to document close-out event writing.

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
- Add GitHub Actions CI workflow configuration (`.github/workflows/ci.yml`) to run PHPUnit and PHPStan analysis automatically on pushes or pull requests.

## [0.0.1] - 2026-06-12

### Added

- Initial release of L2 Meta-Prompt Compiler and Briefing Manager for coding agents.
- Deterministic scope matching for MEMORY.md and specific active skills/constraints.
- Rejection warnings to notify the agent of previously proposed and rejected designs.
- Outcome-driven warnings to flag rules marked as `HARMFUL` or `IRRELEVANT` in past sessions.
- Dynamic validation plan compiler that lists verification tests for loaded active rules.
- Draft outcome log generation to close the feedback loop.
