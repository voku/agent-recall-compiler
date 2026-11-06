# Native JSON Recall: disposable shadow-index experiment

**Owner:** voku/agent-recall-compiler (not agent-loop or agent-session).
**Upstream tested:** hparadiz/Navi-Brain, fixed public commit
`39880c39e0d5af2bff5c3ba5f91c0b94aecbbff8`.

This is an opt-in GitHub Actions **experiment**, not a production integration
or native dependency in Composer. Native `tokmem` is compiled from the public
upstream repository at run time. No upstream source is copied into this repo.
Navi-Brain declares no permissive license; this experiment deliberately does
not redistribute its implementation.

## Data flow

1. **Source of truth:** three existing, tracked JSON documents: task brief,
   completed recall log, and operating-prompt recipe metadata.
2. Read the exact approved source files. Derive a bounded JSON projection from
   explicit typed fields, with `id`, `source_ref`, raw-file
   `source_sha256`, `content`, and `usage=context_only`.
3. Materialize these **derived** canonical JSON strings in a temporary native
   token store on the CI runner (this is not a second authority source).
4. Retrieve candidates with native `ACTIVATE`. Treat its output as **untrusted
   input**. A candidate is accepted only if its entire byte representation
   equals a freshly computed projection of the current authoritative JSON.
5. Confirm that changed canonical JSON, forged native content, truncated native
   output, or unknown candidate identities are rejected. The experiment also
   checks idempotent receipt replay and store integrity.

There are no credentials, private data, hosted-model calls, GitHub writes, or
production workflow mutations. No query result can grant task approval, widen
edit scope, prove review acknowledgement, or change Learning state.

## Runtime and persistence constraints

The native store and Unix socket live exclusively in an ephemeral runner
temporary directory. No GitHub artifact is imported as trusted future memory.
Only read-only diagnostic JSON is uploaded. A future production design would
need explicit project isolation, backup/restore, locking and index rebuilding
from the immutable current source snapshot. CI cache alone cannot become a
source of truth.

## What this measures

Machine-readable results report native activation candidates and how many
remain valid against **current** JSON. The individual target-hit rate is an
observational ranking metric, not an acceptance criterion. Model quality is not
tested here, and this deliberately **does not** add a RecallProvider or change
normal compilation. Evidence first, architectural coupling later.

The earlier native-only corpus test lives in voku/agent-session#38. This test
adds the missing JSON reconciliation and provenance boundary in the actual
Recall owner.

## Reproduce

Clone the fixed upstream revision and build `memories/build/tokmem` with
C17, pthreads and libsqlite3 development headers, then run:

```bash
python3 experiments/native-json-recall/probe.py \
  --root . \
  --binary /path/to/Navi-Brain/memories/build/tokmem \
  --output /tmp/native-json-recall.json
```
