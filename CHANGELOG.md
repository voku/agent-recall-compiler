# Changelog

All notable changes to `voku/agent-recall-compiler` will be documented in this file.

The format follows Keep a Changelog, and this project uses semantic versioning where practical.

## [Unreleased]

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
