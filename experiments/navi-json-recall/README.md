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

`benchmark.py` compares six read-only selection policies on the exact same
22 source prompt records:

1. `json_exact_id`: direct current JSON identity lookup, no fuzzy retrieval.
2. `sqlite_fts5`: SQLite FTS5 `unicode61` BM25 ranking over ID and template.
3. `native_activate`: C `ACTIVATE` using a fixed native token limit, followed
   by complete canonical-JSON byte verification.
4. `json_first_fts5`: exact JSON ID when provided; otherwise SQLite FTS5.
5. `metadata_bounded_choice`: structured owner JSON metadata (`operating-prompts.metadata.json`
   covering titles, descriptions, purposes and typed argument examples) with field-weighted
   SQLite BM25 and a lexical whole-token gate that sometimes abstains.
6. `metadata_coverage_gate_0_6`: exploratory post-hoc ablation requiring
   60% of the non-stopword query tokens to occur in the candidate document.
Each method is post-filtered using **complete identical source-bound envelopes**
at 2,048 and 8,192 **UTF-8 bytes**, preserving order, never truncating a
record. This equalizes delivered bytes, not candidate-generation algorithms,
native tokens, startup cost, or query-processing overhead.

The reported top-1 and top-3 measurements show only whether a prelabelled
candidate is retrieved. For unrelated queries, they measure **whether a method
abstains from proposing candidates**, not whether an LLM answers correctly.
A candidate excluded because it exceeds the output byte budget is **not**
counted as abstention. The artifact records `budget_censored_all_candidates`
separately to prevent misleading apparent improvements on negative controls.
Candidates remain context-only and never grant authority.

### Observed results across the 30 preregistered queries (at 8 KiB budget)

- `native_activate`: exact-ID **2/4**, English paraphrase top-1 **13/16** (top-3 **14/16**), German **0/6** (top-3 **2/6**), unanswerable abstention **0/4 (100% false positives)**, latency ~**1.04 ms**.
- `sqlite_fts5`: exact-ID **4/4**, English paraphrase top-1 **12/16** (top-3 **15/16**), German **1/6** (top-3 **2/6**), unanswerable abstention **0/4 (100% false positives)**, latency ~**0.09 ms**.
- `json_first_fts5`: exact-ID **4/4**, English paraphrase top-1 **12/16** (top-3 **15/16**), German **1/6** (top-3 **2/6**), unanswerable abstention **0/4 (100% false positives)**, latency ~**0.04 ms**.
- `metadata_bounded_choice`: exact-ID **4/4**, English paraphrase top-1 **13/16** (top-3 **15/16**), German **0/6** (top-3 **2/6**), unanswerable abstention **4/4 (0% false positives, 100% clean abstention)**, latency ~**0.07 ms**.

Timing is one small warmed Ubuntu runner sample, includes native transport and
provenance checks, and is **not** a statistically significant benchmark.
Do not claim a quality/performance win from a few milliseconds or the tiny
prompt catalog. Evaluate with real previous-run outcomes and unseen tasks.

The offline fallback check stops the native daemon, then verifies that
JSON identity, SQLite search and metadata bounded choice still work without
a running C process. Native failure therefore cannot become a prerequisite for
deterministic Recall, although no production integration or runtime fallback
is introduced.

### Adversarial follow-up and real owner-task probes

`issue-challenge-queries.json` is kept separate from the original 30 queries.
It includes **eight public, current `voku/agent-loop` issues** (issue references
included) and **eight hard off-domain controls** that deliberately share words
with engineering workflow recipes. The issue-to-recipe labels are independently
drafted *hypotheses*, **not** source-authoritative workflow choices or evidence
of improved agent decisions.

Exact-head CI reports all 46 queries at 2,048 and 8,192 UTF-8 bytes. Observed
at 8,192 bytes on a warmed runner:

| Test (top-1 unless specified) | Native | Metadata lexical | Metadata 60% gate |
| --- | ---: | ---: | ---: |
| Eight owner issue intents | 2/8 | 8/8 | 8/8 |
| Owner issue intents, top-3 | 3/8 | 8/8 | 8/8 |
| Eight hard off-domain queries correctly abstained | 0/8 | 0/8 | 7/8 |
| Four easy off-domain queries correctly abstained | 0/4 | 4/4 | 4/4 |
| Sixteen English paraphrases | 13/16 | 14/16 | 11/16 |
| English paraphrases, top-3 | 14/16 | 15/16 | 12/16 |
| Six German paraphrases | 0/6 | 6/6 | 6/6 |
| Four exact prompt IDs | 2/4 | 4/4 | 4/4 |
| Total negative abstention (easy + hard) | 0/12 | 4/12 | 11/12 |

**These are in-sample observations, not independent evidence of retrieval
quality.** In the source data at this PR head, **16 of 46 registered query
strings occur verbatim in the expected recipe's owner metadata aliases/intents**,
and two further queries contain or are contained in those phrases. These 46
queries were part of the development feedback loop. The 8/8 owner-issue scores
are therefore not a valid untouched test-set result. The new standalone
`offline_baseline.py` now records exact label overlap in the evidence JSON and
runs an additional `metadata_without_alias_intents` ablation to expose this.

**Previous 12/12 result withdrawn:** The removed Python
`bounded_choice_rerank` *default* used a fixture-specific list of words
(`train`, `hospital`, `birthday`, etc.) to reject off-domain tasks. This was
**not a model call or bounded semantic classification**; its 100% result
cannot justify production behavior. A model-free bounded-choice function now
abstains, instead of pretending that a hardcoded domain blacklist is an LLM.


### IT-Portal Bounded Choice Contract

The typed PHP `BoundedChoicePromptBuilder` formats a closed
`cand_0..cand_N, none` model prompt from verified candidates. The
`BoundedChoiceValidator` now accepts **only** a well-formed JSON object
containing exactly one `selected` property with canonical `cand_N` or
`none`; malformed text, unlisted candidate IDs, out-of-bounds indexes,
preambles and additional properties all fail closed to `null`.

Model outputs, even when syntactically valid, remain strictly context-only; they
cannot grant workflow authority or override approved JSON state.

### Independent Blind Holdout Benchmark & Live Model Evaluation

To eliminate all label leakage and test-set contamination (where 16/46 queries in the development set appeared verbatim in metadata aliases/intents), `blind_holdout_queries.json` provides 26 **completely untouched, held-out queries** verified by `test_metadata_retrieval.py` to have **zero exact or subphrase overlap** with any registered alias or intent:

1. **10 Blind Positives:** Real software engineering tasks spanning various SDLC stages (bug reproduction, dead code removal, regression bisect, breaking API change review, missing input clarification, handoff, TDD, adversarial review, missingness audit, autonomous continue).
2. **8 Hard Domain Negatives:** Non-software engineering scenarios sharing technical vocabulary (water pipeline rust inspection, corporate merger legal terms, apple orchard pruning, cycling sprint training, financial audit, family Thanksgiving conflict, perimeter fence inspection, sourdough hydration).
3. **8 Blind Out-of-Catalog Tasks:** Genuine software engineering tasks for which **no recipe exists** in the 22 operating prompts (Nginx SSL proxy, CSS grid layout, Postgres autovacuum tuning, PyTorch CNN training, Ansible provisioning, Prometheus/Grafana setup, ARM NEON assembly crypto, Blender procedural mesh script).

`run_blind_holdout_llm_benchmark.py` evaluates these 26 cases against four policies, including live model inference parsed through the strict `BoundedChoiceValidator`:

| Cohort | Total Queries | SQLite FTS5 | Metadata Lexical | Metadata 60% Gate | Live LLM Bounded Choice |
| --- | ---: | ---: | ---: | ---: | ---: |
| **Blind Positives (Top-1)** | 10 | 6/10 (60.0%) | 7/10 (70.0%) | **0/10 (0.0%)** | **7/10 (70.0%)** |
| **Hard Domain Negatives (Abstention)** | 8 | 0/8 (0.0%) | 4/8 (50.0%) | 8/8 (100.0%) | **8/8 (100.0%)** |
| **Out-of-Catalog Tasks (Abstention)** | 8 | 0/8 (0.0%) | 7/8 (87.5%) | 8/8 (100.0%) | **5/8 (62.5%)** |
| **Total Clean Abstention (Negatives + Out-of-Catalog)** | 16 | 0/16 (0.0%) | 11/16 (68.8%) | 16/16 (100.0%) | **13/16 (81.3%)** |

#### Critical Empirical Takeaways

1. **Falsification of the 60% Coverage Filter:** The 60% word-overlap gate achieves 100% negative abstention only because it rejects **100% of independent positive tasks (0/10 correct)**. It is thoroughly overfitted and unusable in practice.
2. **True Semantic Abstention via Live LLM:** Live LLM Bounded Choice achieved **100% clean abstention (8/8) on the hard domain negatives**, correctly choosing `none` when presented with distractor candidates matching technical words.
3. **Out-of-Catalog Boundaries:** On tasks outside the catalog, the model abstains in 5/8 cases; in 3 cases, it selected `multi-pass-correctness-simplify` due to that recipe's overly broad description. Refining prompt descriptions to be more specific is the next architectural lever.
4. **Deterministic Fail-Closed Boundary:** Timeouts, empty responses, or invalid syntax (observed in 1 case) fail closed to `None`, preserving safety without crashing the workflow.


### Verified correctness changes (RED → GREEN)

The `test_metadata_retrieval.py` regression suite first reproduced seven
failures on the prior implementation. The native Actions workflow now executes
that suite before compiling C, and independently runs `composer ci` on
PHP 8.3, 8.4 and 8.5 against the **exact stacked PR head**. Fixed behaviors:

- Missing, symlinked, invalid or oversized metadata JSON fails with context.
- Duplicate, unknown, and missing metadata identities are rejected against
  the current authoritative prompt catalog.
- SQLite errors propagate as errors rather than silently returning `[]`.
- A substring inside another word never constitutes another lexical match.
- Stopword-only queries abstain; all candidate records are complete and
  remain bounded by the same byte budget.

### Decision gate

**Do not promote the native backend, the existing metadata lexical gate, or
the 60% coverage gate to production.** Current evidence supports only the
read-only authoritative JSON/provenance boundary and safe failure semantics.
A useful next discriminating experiment requires a separate, not-tuned-on,
representative sample of task intents and negative cases, a clear relevance
oracle, and an independent bounded-choice semantic reranker *if* a permitted
model is available.

A policy that improves `none` at the cost of discarding real owner tasks is
not a win. Keep source-of-truth and workflow authority deterministic.


### Independent JSON/SQLite-only CI job

\`offline_baseline.py\` executes all 46 registered queries against the **same
source JSON and scorer functions** without invoking or installing Navi-Brain.
It calls neither a C binary nor a native daemon; the GitHub Actions job runs
in parallel with the native test, so native checkout, build, or service
failures cannot suppress JSON/SQLite evidence.

The independently uploaded \`offline-json-recall-evidence\` artifact contains
all nonnative condition results, source and metadata SHA-256 digests, query
fixture hashes, output-budget checks, and the observed cohort scores. The
\`native-json-recall-evidence\` artifact remains separate. Both are ephemeral
diagnostic outputs and never become authoritative workflow state.

The independent path is verified at
https://github.com/voku/agent-recall-compiler/actions/runs/42300810008
alongside the regular PHP 8.3–8.5 Composer CI and the native test.
The result confirms **test isolation**, not improvement to coding-agent outcomes.

### Final verification boundary

A GREEN Actions job proves the implementation and test invariants execute;
it does not confer independent semantic accuracy. Before any runtime
integration, use a genuinely unseen set of relevant software and off-domain
tasks, independently annotated candidate relevance, model selection and
abstention metrics, and an explicit provider-neutral opt-in/failure policy.
The typed selection contracts may be reviewed separately from the
experiment, but these results do **not** validate an LLM as a decision-maker.
