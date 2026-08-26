# Adapting anti-premature-completion ideas without duplicating owners

`Leonxlnx/unlazy` provides useful completion-discipline ideas, but Recall should adopt reusable behavioral invariants rather than its standalone state machine.

## Adopt

- reconcile completion claims against current observable evidence;
- do not treat prior agent prose or an unexecuted command as verification;
- keep delegated execution prompts bounded to one current slice;
- make multi-pass review converge on evidence-backed changes rather than a fixed arbitrary effort count;
- keep integration review distinct from lifecycle authority when composition requires non-deterministic judgment.

## Do not copy

- `.unlazy/PLAN.md`, `GATES.md`, dispatch leases, or Stop-hook state as Recall state;
- depth-as-effort arithmetic;
- a second validation/evidence authority beside Loop/Session;
- synonymous recipes where an existing Recall recipe already owns the behavior.

## Owner split

- Recall owns HOW an agent reasons, reviews, verifies, and reports.
- `agent-loop` owns WHEN a behavior is required, lifecycle legality, Contract/Run authority, and final completion policy.
- Session / execution evidence owns what actually ran and which implementation identity it observed.
- host skills and UI remain thin consumers/projections.

This note records design provenance only. The implementation remains independently expressed in Recall's existing recipe vocabulary rather than copying upstream prompt text.
