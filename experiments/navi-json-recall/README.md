# Experimental JSON-authoritative native recall

This is a CI-only candidate experiment owned by Recall, not a production
provider, workflow policy change, Composer dependency or replacement for JSON.

## Real source

The probe reads the checked-in, structured JSON catalog at
`resources/skills/agent-recall-consumer/operating-prompts.json`. Each
`prompts[].id` and `prompts[].template` becomes one searchable native-memory
entry. The C engine is compiled from the exact public upstream commit
`39880c39e0d5af2bff5c3ba5f91c0b94aecbbff8`. No upstream C implementation
is copied or shipped. The process and store live only inside the disposable
CI runner.

## Trust and provenance rule

Native memory provides **candidate discovery, never authority**.

For every candidate, the experiment reopens the current owner JSON, checks
its exact raw-byte SHA-256, checks the entry identity and stored template,
and returns only a `navigation_candidate` with `use=context_only`.
It does not trust the native candidate's claimed source, content, approval,
lifecycle or executable instructions. Unknown/forged candidates, changed
JSON, and missing JSON are rejected, never silently accepted.

Each returned candidate is a projection of the original JSON bytes. This
does **not** provide a general proof about malicious source files or a
production prompt-injection boundary. Both owner authorization and current
scope remain responsibilities of the existing authoritative APIs.

## Test coverage

- Real owner JSON schema and entry identities.
- Idempotent creation and record count without duplicates.
- Validation of every native record against its source JSON.
- Native ACTIVATE queries for all prompt IDs; exact selection is measured as
  an observational metric, not a pass gate.
- Modified current JSON rejects old native candidates (source SHA drift).
- Forged instruction appended to candidate rejects.
- Unknown IDs and missing source reject.
- Native integrity verifier, clean daemon close and restart revalidation.
- Machine-readable JSON artifact for exact-head review.

No LLM is involved. No comparison of actual coding-agent task outcomes,
recall vs no-memory causality, or deterministic output identity across
different native tokenizers has been established.

## Future production gates

Do not wire this into Loop or replace the existing provider until we can
prove stable typed source references from owner APIs, deterministic replay
identity, bounded retrieval, stale-index recovery, correct failure semantics,
no unauthorized authority extension, cost/latency, and usefulness on held-out
coding-agent tasks. A persisted CI cache is **not** an authoritative store.
Rebuild native search data from current JSON or validate a content-addressed,
untrusted cache before any use.

The upstream's repository license must also be resolved before introducing
a distributed binary or native implementation into the package.
