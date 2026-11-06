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

## Matched-output retrieval benchmark (experimental)

`held-out-queries.json` fixes 30 labelled queries **outside the indexed source**
before running native and SQLite retrieval: four exact IDs, 16 English
paraphrases, six German paraphrases, and four unrelated no-answer controls.
The expected identities are human-written **test labels**, not a model-vetted
semantic truth set. Ambiguous examples should be revisited before treating
them as high-stakes quality gates.

`benchmark.py` compares four read-only selection policies on the exact same
22 source prompt records:

1. `json_exact_id`: direct current JSON identity lookup, no fuzzy retrieval.
2. `sqlite_fts5`: SQLite FTS5 `unicode61` BM25 ranking over ID and template.
3. `native_activate`: C `ACTIVATE` using a fixed native token limit, followed
   by complete canonical-JSON byte verification.
4. `json_first_fts5`: exact JSON ID when provided; otherwise SQLite FTS5.

Each method is post-filtered using **complete identical source-bound envelopes**
at 2,048 and 8,192 **UTF-8 bytes**, preserving order, never truncating a
record. This equalizes delivered bytes, not candidate-generation algorithms,
native tokens, startup cost, or query-processing overhead.

The reported top-1 and top-3 measurements show only whether a prelabelled
candidate is retrieved. For unrelated queries, they measure **whether a method
abstains from proposing candidates**, not whether an LLM answers correctly.
Candidates remain context-only and never grant authority.

Timing is one small warmed Ubuntu runner sample, includes native transport and
provenance checks, and is **not** a statistically significant benchmark.
Do not claim a quality/performance win from a few milliseconds or the tiny
prompt catalog. Evaluate with real previous-run outcomes and unseen tasks.

The new offline fallback check stops the native daemon, then verifies that
JSON identity and SQLite search still work without a running C process.
Native failure therefore cannot become a prerequisite for deterministic
Recall, although no production integration or runtime fallback is introduced.

### Decision gate

Do not make the native dependency mandatory or merge a production adapter until
held-out task retrieval provides a reproducible advantage over an exact-ID plus
SQLite FTS5 baseline at the same bounded output budget, with satisfactory
negative-query abstention, explainable source provenance, and real multi-run
coding-agent outcomes. If the simpler source-backed baseline performs equally
well or better, retain it and drop the native dependency.
