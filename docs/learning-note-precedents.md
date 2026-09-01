# LearningNote precedents

Recall can consume `voku/agent-learning` LearningNotes as deterministic solved-case precedent when the Learning owner is installed. The integration is optional: standalone Recall does not require Learning at runtime, and absence of the owner package contributes no precedent facts.

## Ownership

`agent-learning` owns LearningNote schema, storage, lifecycle, source-Finding lineage, redaction, repository-evidence status, and the public read projection. Recall consumes only that projection. It does not parse `notes/**`, infer Finding validity, repair Learning state, or author LearningNote prose.

For compatibility proof, Recall's development graph pins the exact released Learning owner version exercised by the projection adapter. This remains a development/test dependency rather than a production dependency.

## Deterministic selection

A current LearningNote is eligible only through mechanically available owner metadata:

- exact task-file or scoped path-prefix match;
- project-wide scope;
- exact canonical tag intersection.

Recall does not use embeddings, fuzzy title/prose similarity, model judgement, recency scoring, or repository-wide grep to recover a missing relevance signal.

Eligible notes are ordered by current evidence state, scope specificity, exact tag overlap, and finally stable LearningNote ID. The normal context budget bounds rendered precedent prose and preserves deterministic omission reasons.

## Authority

A LearningNote is precedent, not instruction. `learning_precedent` sits below `project_skill` and above legacy `repository_memory` in explicit fact-conflict precedence. Stronger current Contract/task/ADR/approved Learning/project-skill authority still wins, and precedent never widens task scope or satisfies validation.

When active guidance carries the same explicit non-empty `pattern_key`, Recall preserves the precedent fact and lineage but suppresses duplicate full precedent prose with `covered_by_active_guidance`. It does not infer semantic duplication from prose.

## Staleness and corruption

- `current`: eligible for bounded precedent rendering.
- `review_needed`: remains inspectable as historical provenance, but full case guidance is withheld from normal execution context.
- `no_hashable_repository_evidence`: retained as historical context, not current execution advice.
- missing source or unsupported/corrupt configured owner state: explicit compilation failure rather than empty success.

Recall trusts the Learning owner's projected evidence state. It does not recompute Learning-owned source hashes.

## Explain and replay

Selected and withheld precedents are represented as typed facts and context-explain items with exact selection or omission reasons, source Finding lineage, note digest, and evidence state. Equivalent task input plus equivalent eligible LearningNote projections reproduces the same provider digest and output. Changes to an eligible note change the digest; changes confined to out-of-scope notes do not perturb task-specific precedent output.

## Product dogfood boundary

This package proves deterministic selection, authority, explainability, replay, and compatibility with the released Learning projection. The cross-run product proof belongs to `voku/agent-loop#349`: one real task produces an evidence-backed LearningNote, transient Session/chat state is discarded, and a later related task receives the precedent through normal `agent-loop enter` without a new mandatory lifecycle command.
