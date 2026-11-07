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

`blind_holdout_queries.json` provides 26 **additional evaluation queries**. The CI regression test verifies **no complete-query or full-alias/intent subphrase overlap** against the real 22-recipe catalog. This is stronger than the old two-record stub, but it is **not proof of zero word overlap, temporal independence, blind authoring, or lack of tuning**:

1. **10 Blind Positives:** Real software engineering tasks spanning various SDLC stages (bug reproduction, dead code removal, regression bisect, breaking API change review, missing input clarification, handoff, TDD, adversarial review, missingness audit, autonomous continue).
2. **8 Hard Domain Negatives:** Non-software engineering scenarios sharing technical vocabulary (water pipeline rust inspection, corporate merger legal terms, apple orchard pruning, cycling sprint training, financial audit, family Thanksgiving conflict, perimeter fence inspection, sourdough hydration).
3. **8 Blind Out-of-Catalog Tasks:** Genuine software engineering tasks for which **no recipe exists** in the 22 operating prompts (Nginx SSL proxy, CSS grid layout, Postgres autovacuum tuning, PyTorch CNN training, Ansible provisioning, Prometheus/Grafana setup, ARM NEON assembly crypto, Blender procedural mesh script).

`run_blind_holdout_llm_benchmark.py` can invoke the **local** `agy --print` CLI and checks its responses through a **Python mirror** of the PHP `BoundedChoiceValidator`. The recorded file below contains responses attributed to that local run; GitHub Actions does **not** execute the model. The new `replay_blind_holdout.py` and `verify_php_blind_replay.php` check all 26 recorded outputs against current JSON, deterministic candidate rankings, and the **actual PHP** validator on PHP 8.3–8.5. These CI checks validate recording/contract consistency, not independently witnessed model execution. Model/provider/version/temperature, invocation logs and an external model-attested run are not included in the evidence:

| Cohort | Total Queries | SQLite FTS5 | Metadata Lexical | Metadata 60% Gate | Live LLM Bounded Choice |
| --- | ---: | ---: | ---: | ---: | ---: |
| **Blind Positives (Top-1)** | 10 | 6/10 (60.0%) | 7/10 (70.0%) | **0/10 (0.0%)** | **7/10 (70.0%)** |
| **Hard Domain Negatives (Abstention)** | 8 | 0/8 (0.0%) | 4/8 (50.0%) | 8/8 (100.0%) | **8/8 (100.0%)** |
| **Out-of-Catalog Tasks (Abstention)** | 8 | 0/8 (0.0%) | 7/8 (87.5%) | 8/8 (100.0%) | **5/8 (62.5%; 2 explicit none + 3 empty)** |
| **Total Clean Abstention (Negatives + Out-of-Catalog)** | 16 | 0/16 (0.0%) | 11/16 (68.8%) | 16/16 (100.0%) | **13/16 (81.3%)** |

#### Critical Empirical Takeaways

1. **Falsification of the 60% Coverage Filter:** The 60% word-overlap gate achieves 100% negative abstention only because it rejects **100% of independent positive tasks (0/10 correct)**. It is thoroughly overfitted and unusable in practice.
2. **Recorded model outputs:** All 8 hard domain negatives contain explicit JSON `none` responses in the local transcript. Because no model call ran in GitHub Actions, this is **recorded local evidence verified by deterministic replay**, not a CI-reproducible model success rate.
3. **Out-of-Catalog Boundaries:** The recorded local responses contain only **2/8 explicit semantic `none` choices**, plus **3/8 empty responses** that the fail-closed parser treated as `none`. In the remaining 3 cases the recorded output selected `multi-pass-correctness-simplify` (CSS Grid, Ansible, ARM NEON). Whether broad recipe text explains these errors requires a controlled intervention, not speculation.
4. **Deterministic Fail-Closed Boundary:** All **4 empty recorded outputs** (one positive, three out-of-catalog) are rejected as `none` by the PHP parser. The recorded responses do not establish whether empties were model timeouts, output transport losses, or another failure; no causal label is justified.


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


### Post-hoc candidate-fusion experiment (no metadata tuning)

`candidate_fusion_ablation.py` evaluates a separate, fixed-hyperparameter
**candidate discovery** step. It never calls a model, changes canonical JSON,
or edits descriptions/aliases/intents. All candidate identities must originate
from the owner catalog. The independent **native-free GitHub Actions** job
writes `evidence/candidate-fusion-ablation.json` with per-query rankings,
source digests and the fixed RRF constant (60).

The 26 already-inspected blind-holdout cases are now **development data**.
Any improvement here is diagnostic, not independent generalization.

| Post-hoc source-backed strategy | Gold recipe offered in top 3 (10 positives) | Gold offered in top 5 | Hard-negative candidate exposures in top 3 (8 queries) | Out-of-catalog candidate exposures in top 3 (8 queries) |
| --- | ---: | ---: | ---: | ---: |
| Metadata alone | 8/10 | 8/10 | 5 | 1 |
| Plain FTS5 alone | 6/10 | 7/10 | 24 | 24 |
| Metadata-first union, capped 6 | 8/10 | **9/10** | **24** | **24** |
| Reciprocal rank fusion, k=60, capped 6 | 7/10 | 9/10 | **24** | **24** |

**Exposure** counts candidate suggestions, not model-selected false positives.
They are the sum of offered candidates across eight tasks; 24 means three
candidates per task. The union gains one possible positive candidate *only*
by raising the candidate limit to five, while exposing many unrelated
choices. The RRF strategy even loses one top-three positive. Neither is a
production improvement, and neither attains the proposed 100% Recall@3 gate.

**Separate the second lever:** The three previously recorded incorrect
`multi-pass-correctness-simplify` outputs are attributed to selection only
at the candidate-response boundary; the causal claim that its wording is
too broad is untested. Do **not** edit that authoritative recipe against
these already-seen negative cases and claim independent quality. Test a
fixed, narrower proposal only against a *new* frozen corpus and real model
invocations with version/configuration evidence, retaining negative controls
and candidate-recall measurements.

### Paired Applicability A/B experiment (recorded local inference)

`applicability_ab_queries.json` contains **five contrast pairs / ten queries**:
`topic_only` versus `workflow_demanded`. The recorded outcomes in
`evidence/applicability-ab-benchmark.json` were produced by a local `agy
--print` script and are not re-executed by GitHub Actions.

For each individual query, A and B receive the **same candidates**. However,
the A/B harness forces `target_recipe` into the offered list if FTS5 did
not discover it. The independent CI replay records **four out of ten forced
target insertions**; this experiment isolates prompt **selection**, not
end-to-end candidate discovery. Also, A is always called before B (no
randomized ordering); model identity/configuration and individual request
provenance are not externally captured. Fixture and output were committed
together, so a `frozen before evaluation` statement is not independently
attested by Git history.

| Metric (10 recorded cases) | Legacy A | Applicability B |
| --- | ---: | ---: |
| Workflow explicitly demanded: correct recipe | 5/5 | 5/5 |
| Topic-only: wrong recipe selected | 2/5 | 0/5 |
| Topic-only: explicit valid `{"selected":"none"}` | 1/5 | 5/5 |
| Empty raw responses | 2/10 | 0/10 |
| Correct including fail-closed empty responses | **8/10** | **10/10** |
| Explicitly correct, excluding empty replies | **6/10** | **10/10** |

The preexisting summary `7/10` for A does **not** match either
fully specified counting policy. `8/10` includes safe empty responses,
and `6/10` requires an explicit semantic answer. The new runner
`replay_applicability_ab.py` recomputes candidate lists from current
canonical source, checks all ten stored responses/labels and reports both
definitions separately. `verify_php_applicability_ab.php` replays both
variants through the **actual PHP validator** on 8.3, 8.4 and 8.5.
Mutation regression tests reject changed gold labels, candidate lists,
or decoded model decisions.

The three **previously seen** CSS Grid / Ansible / ARM NEON cases are
**not among these ten queries** and must not be claimed as three repaired
cases on this evidence. A future untouched task set is required to
establish transfer beyond the five contrast pairs.

**Interpretation:** This is promising *local recorded* evidence for the
applicability-prompt hypothesis. CI confirms deterministic replay,
provenance hashes and parser agreement; **CI does not call a model or
demonstrate independent replication of the observed inference**. Keep
the opt-in default and the original authority boundaries unchanged.

### Final verification boundary

The independent recorded-response replay was added at [GitHub Actions #42307637309](https://github.com/voku/agent-recall-compiler/actions/runs/42307637309), including the genuine PHP validator on PHP 8.3–8.5. In this sample, **8/10 target recipes were present among the offered candidates**, and **7/8 offered positives** were correctly selected; two failures were candidate-generation misses, and one was an empty recorded response. Recorded correct rejection is **13/16**, but only **10/16** outputs are explicit JSON `none` (8 hard negatives, 2 out-of-catalog). The other 3 are empty replies; the remaining three are incorrect selections.

A GREEN Actions job proves the implementation and test invariants execute;
it does not confer independent semantic accuracy. Before any runtime
integration, use a genuinely unseen set of relevant software and off-domain
tasks, independently annotated candidate relevance, model selection and
abstention metrics, and an explicit provider-neutral opt-in/failure policy.
The typed selection contracts may be reviewed separately from the
experiment, but these results do **not** validate an LLM as a decision-maker.
