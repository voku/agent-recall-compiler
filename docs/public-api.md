# Public PHP API

## Recall compilation

Stable host-facing Recall compilation uses:

- `voku\AgentRecallCompiler\CompileRequest`
- `voku\AgentRecallCompiler\RecallCompiler`
- `voku\AgentRecallCompiler\CompileResult`

Command classes, CLI option arrays, provider construction, and console output are implementation details. See `embedding.md` for the integration contract.

## Review audit preparation

Hosts that need Recall to prepare the deterministic blind-spot evidence audit use:

- `voku\AgentRecallCompiler\Review\ReviewAuditPreparer`
- `voku\AgentRecallCompiler\Review\ReviewReportArtifact`
- `voku\AgentRecallCompiler\Review\ReviewReport`

`ReviewAuditPreparer::prepare()` owns the deterministic audit, optional Contract-revision/implementation-snapshot binding, report persistence, semantic L2 prompt handoff, and exact persisted report identity. The optional Contract revision and implementation snapshot must be provided together.

The returned `ReviewReportArtifact` is evidence, not lifecycle authority. Recall does not decide whether the report is current for a host Run, acknowledge it, approve the implementation, or execute the emitted semantic review prompt.

Hosts should not reproduce audit preparation by composing `BlindSpotReviewer`, `ReviewReportWriter`, or other Review implementation pieces directly. Those classes remain implementation details even though they are PSR-4 autoloadable before 1.0.

## Review report evidence

Lifecycle hosts that only need the current persisted deterministic audit result use:

- `voku\AgentRecallCompiler\Review\ReviewReportReader`
- `voku\AgentRecallCompiler\Review\ReviewReportArtifact`
- `voku\AgentRecallCompiler\Review\ReviewReport`

`ReviewReportReader::read()` returns `null` when no report exists. A present but invalid, mismatched, or internally inconsistent report fails explicitly. A valid result includes the typed report, canonical JSON path, and SHA-256 identity of the exact persisted JSON bytes.

The report digest is evidence identity only. A lifecycle host may bind its own acknowledgement or close-out decision to that identity, but Recall does not manufacture acknowledgement authority merely because a report exists.

Hosts should not reconstruct the `reviews/<task>.blindspots.json` path, parse its JSON schema, validate its Contract/snapshot binding, or compute its digest independently.
