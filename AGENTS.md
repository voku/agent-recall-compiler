# AGENTS.md

## Repository role

`voku/agent-recall-compiler` owns deterministic Recall semantics: bounded context selection, provenance/explanation, Recall-owned artifacts, operating-prompt recipe catalog/rendering, recipe applicability metadata, review-artifact identity, and immutable Recall selection/outcome evidence.

Recall turns task intent plus bounded evidence into replayable context and prompt material. It does not own workflow approval, mutation authority, implementation success, review acknowledgement, governed close-out, or automatic durable Learning decisions.

## Dependency direction

Recall currently depends on `voku/agent-map` for repository navigation evidence. Keep that direction narrow.

- Use Map-owned typed APIs/plans for source/navigation semantics; do not copy Map schema or analysis policy into Recall.
- Do not add runtime dependencies on `agent-loop`, `agent-session`, `agent-learning`, `agent-kanban`, `agent-ui`, or `agent-loop-runner` to answer lifecycle questions.
- Higher-level consumers should use Recall's typed public PHP APIs such as compiled-output readers, context projections, review readers, and `OperatingPromptCatalog`; they should not parse Recall JSON/files or duplicate recipe manifests.

## Invariants to preserve

- Context selection is deterministic and bounded. Relevant context is not edit permission.
- Provenance is part of the contract: source refs, digests, compilation identity, Contract/run binding, selected/excluded reasons, and integrity state must not be replaced by plausible prose.
- Missing evidence stays missing/unknown/blocked. Do not invent commands, files, lifecycle state, or authority because a package/recipe suggests they probably exist.
- Operating-prompt recipes are selected explicitly. Recall owns recipe semantics, typed arguments, template identity, purpose, applicability metadata, and whether extension points such as free-form instructions are allowed.
- Recipe applicability is metadata for embedding hosts; workflow/lifecycle authority still belongs to `agent-loop`. Do not turn Recall into a second phase machine.
- Review artifact identity proves which artifact was read, not that it was acknowledged or accepted.
- Selection/outcome events are evidence for Learning. Recall must not automatically promote, weaken, retire, or rewrite durable guidance from those events.
- L2 prompt material may ground a concrete L1 construction, but Recall itself does not execute production edits or declare completion.

## Implementation guidance

Prefer typed owner APIs and immutable projections over exposing private file layout. When a consumer needs a new Recall fact, add the smallest read-only typed projection at the Recall boundary rather than teaching the consumer a filename or JSON key.

Keep prompts deterministic: no timestamps or volatile data in replay identity unless explicitly outside the deterministic contract. Validate direct PHP construction as strictly as CLI input so hosts cannot bypass type/shape checks.

## Validation

Run:

```bash
composer ci
```

This includes strict Composer validation, PHPUnit, and PHPStan with the repository's explicit analysis memory budget.

## Releases

Releases are marker-driven. First land a release-ready commit whose own `CHANGELOG.md` contains the version, then add `.release/<version>.json` pointing to that exact commit. The release workflow validates ancestry and immutable tag identity; do not retarget existing tags.
