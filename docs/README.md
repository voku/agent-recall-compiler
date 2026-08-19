# Documentation map

The README explains what `agent-recall-compiler` is and how to start using it. The documents here separate the deeper contracts by responsibility instead of repeating one giant prompt/compiler description everywhere.

## Start here

- [Design principles](design-principles.md) — the architectural rules behind prompt compilation, bounded context, provenance, evidence states, review, Learning evidence, and authority boundaries.
- [CLI reference](cli-reference.md) — detailed compile, target/map, manifest, outcome, review, and prompt commands kept out of the top-level README.
- [Operating prompt recipes](operating-prompts.md) — L1/L2 prompt levels, `Goal / Context / Constraints / Verification / Done When`, capability evidence, governed input, and `agent-loop` integration.
- [Prompt primitives](prompt-primitives.md) — when to use L2 construction, direct L1 controls, first-draft review, future-work reflection, or guidance-gap journaling.

## Starter integration

Use the existing examples and shipped skills instead of embedding another long Recall policy into every task:

- [`examples/agent-learning/config.json`](../examples/agent-learning/config.json) — starter Learning-root configuration for Recall-related policy.
- [`examples/agents/skills/project-agent-recall/SKILL.md`](../examples/agents/skills/project-agent-recall/SKILL.md) — optional repository-local Recall wrapper.
- [`skills/agent-recall-consumer/SKILL.md`](../skills/agent-recall-consumer/SKILL.md) — package-neutral consumer contract.
- [`skills/agent-recall-compiler-maintainer/SKILL.md`](../skills/agent-recall-compiler-maintainer/SKILL.md) — maintainer workflow for this package.

## Context and compilation

- [Recall provider architecture](recall-provider-architecture.md) — provider boundaries, governed-vs-standalone task input, fact precedence, source digests, target-aware context, artifact semantics, and compatibility.
- [Context Explain](context-explain.md) — deterministic `WHAT / WHY / HOW / AUTHORITY / USE / STATE` provenance and context-use semantics.
- [Dependency readiness](dependency-readiness.md) — distinguishing dependency/environment readiness from a defect in the component under review.

## Integration

- [Embedding Recall](embedding.md) — host-facing integration boundary for applications embedding the compiler.
- [Public PHP API](public-api.md) — stable compilation types plus typed review-report evidence identity.
- [Agent-loop review follow-up](agent-loop-review-follow-up-prompt.md) — integration handoff for the review workflow.

## Evidence and close-out

- [Guidance event history](guidance-events.md) — immutable selection/outcome evidence, deliberate unjudged outcomes, and the distinction between selection, application, usefulness, and durable Learning policy.

## Mental model

Use this ownership split when deciding where a new behavior belongs:

```text
standalone task input
or governed approved Contract envelope
        ↓
Recall
  - select bounded evidence
  - explain provenance
  - validate/render prompt semantics
  - prepare validation/review artifacts
  - append immutable Recall outcome evidence when log-outcome is invoked
        ↓
receiving agent / host
  - construct L1 when an L2 recipe requires it
  - execute implementation
  - run verification
        ↓
lifecycle owner
  - acknowledge review
  - decide close-out
        ↓
Learning owner
  - evaluate durable promotion / retirement policy
```

A useful fact flowing through Recall does not automatically make Recall the authority that acts on that fact. Persisting an immutable outcome event likewise does not make Recall the owner of durable Learning policy. Those two boundaries are the thread connecting most of these documents.
