# Public PHP API

## Recall compilation

Stable host-facing Recall compilation uses:

- `voku\AgentRecallCompiler\CompileRequest`
- `voku\AgentRecallCompiler\RecallCompiler`
- `voku\AgentRecallCompiler\CompileResult`

Command classes, CLI option arrays, provider construction, and console output are implementation details. See `embedding.md` for the integration contract.

## Review report evidence

Lifecycle hosts that need the current blind-spot review result use:

- `voku\AgentRecallCompiler\Review\ReviewReportReader`
- `voku\AgentRecallCompiler\Review\ReviewReportArtifact`
- `voku\AgentRecallCompiler\Review\ReviewReport`

`ReviewReportReader::read()` returns `null` when no report exists. A present but invalid, mismatched, or internally inconsistent report fails explicitly. A valid result includes the typed report, canonical JSON path, and SHA-256 identity of the exact persisted JSON bytes.

The report digest is evidence identity only. A lifecycle host may bind its own acknowledgement or close-out decision to that identity, but Recall does not manufacture acknowledgement authority merely because a report exists.

Hosts should not reconstruct the `reviews/<task>.blindspots.json` path, parse its JSON schema, validate its Contract/snapshot binding, or compute its digest independently.
