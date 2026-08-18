# Design principles

`agent-recall-compiler` is built around one operational assumption: coding agents are fast enough that **context quality, evidence, and authority boundaries** become more important than adding more generic instructions to a prompt.

Recall therefore treats prompting as a compilation problem rather than a prose-generation problem.

```text
Task intent
+
approved Contract
+
bounded repository facts
+
relevant guidance
+
constraints
+
prior outcome evidence
        ↓
     COMPILE
        ↓
project-specific operational context
        ↓
optional L2 → L1 construction
        ↓
execution
        ↓
verification / review / learning by their proper owners
```

This document describes the design rules behind that model. The implementation details live in the specialized documents linked throughout.

## 1. Compile project-specific prompts instead of growing universal prompts

Reusable guidance should normally describe **how to construct** a task-specific operational contract, not hard-code every repository-specific command, path, framework, and historical rule.

Recall distinguishes:

- **L2 recipe**: reusable construction method and quality bar;
- **L1 contract**: concrete executable instructions for the current task.

The target L1 shape is intentionally small:

```text
Goal
Context
Constraints
Verification
Done When
```

`Goal` defines the observable outcome. `Context` contains current project facts. `Constraints` bound scope and forbidden shortcuts. `Verification` defines how reality is measured. `Done When` defines the stopping condition.

This split lets one reusable method adapt to different repositories without pretending that `run the tests` or `use the project conventions` is actionable when exact repository evidence is available.

See [Operating prompt recipes](operating-prompts.md).

## 2. Relevant context beats maximum context

A model does not become more correct merely because more repository content is placed in its context window.

Additional context can:

- distract from the task;
- create false relevance;
- widen perceived edit scope;
- introduce stale or conflicting guidance;
- consume tokens without improving evidence.

Recall therefore selects from bounded providers and persists the selection decision. Exact targets can use `agent-map` to derive source-backed edit context such as primary implementations, contracts, direct change candidates, tests, dependencies, type definitions, blind spots, and omissions.

The objective is **maximum relevant context**, not maximum context.

See [Recall provider architecture](recall-provider-architecture.md).

## 3. Selection should explain provenance and permitted use

A flat list of "relevant context" hides important distinctions. Recall can explain selected context with:

```text
WHAT
WHY
HOW
AUTHORITY
USE
STATE
```

These dimensions answer different questions:

- **WHAT** is the selected source, command, document, recipe, or decision?
- **WHY** does it matter to the current task?
- **HOW** was that relationship derived?
- **AUTHORITY** makes the underlying source or claim authoritative?
- **USE** defines what the receiving agent may safely do with it.
- **STATE** records how well the provenance claim is supported.

`HOW` and `AUTHORITY` are deliberately separate. A map may discover a source slice, while the current repository source remains authoritative for the code itself.

See [Context Explain](context-explain.md).

## 4. Context is not edit permission

A file can be required to understand a change without being part of the approved change.

Target-aware context therefore carries usage semantics. Typical roles include:

```text
primary          → implementation candidate
contract         → compatibility contract
change_candidate → inspect and edit if required
verification     → verification context
dependency       → context only
type_definition  → context only
```

Unknown future roles fail closed to context-only semantics until their meaning is verified.

This prevents context discovery from silently expanding task scope.

## 5. Evidence state matters more than model confidence

Recall uses explicit epistemic labels for material conclusions:

```text
VERIFIED
INFERRED
ASSUMED
BLOCKED
CONTRADICTED
```

The labels describe the relationship between a claim and available evidence. They are more useful than an uncalibrated confidence percentage.

In particular:

- model confidence is not verification;
- prior implementation rationale is not verification;
- reviewer consensus is not verification;
- an unexecuted command is not verification;
- successful prompt generation is not verification;
- unavailable evidence remains `UNKNOWN` / `BLOCKED` rather than being replaced with plausible prose.

This makes `BLOCKED` a valid result instead of forcing every workflow to manufacture success.

## 6. Mechanical verification should own mechanical facts

The coding agent should not simultaneously be implementation author, test runner, reviewer, and final authority.

When repository-supported mechanisms can establish a fact, use them:

```text
tests
static analysis
mutation tests
benchmarks
schema validation
artifact digests
snapshot identity
exact changed-file checks
```

Recall compiles validation obligations and exact commands when repository evidence supports them. It does not execute those commands during compilation and does not infer success merely because the plan exists.

`Verification` and `Done When` remain separate because measurement procedure and acceptance threshold are different contracts.

## 7. Adversarial review produces hypotheses, not automatic truth

Recall's review primitives deliberately use first-draft falsification and blind-spot analysis to search for failures.

That adversarial posture is useful for generating hypotheses. It does not make a concern true merely because a reviewer can imagine it.

A material blind-spot concern should move toward evidence by identifying:

```text
claim
evidence
epistemic state
failure chain
earliest observable signal
smallest discriminating probe
why existing verification allowed the failure to escape
```

Dogfood runs are most useful as **discriminating experiments**. Depending on the hypothesis, the relevant probe may be a source checkout, a clean installed consumer, a cross-package release set, a repeat/resume path, or a no-change comparison.

A review with no demonstrated change required is a valid outcome. Finding quotas and numeric review scores are not evidence.

See [Prompt primitives](prompt-primitives.md).

## 8. Do not give execution the verifier's answer key

When repository evidence can generate verification questions and canonical accepted answers, those are different artifacts for different consumers.

The execution agent may receive a public verification plan. An independent verifier may receive the canonical answer key and source evidence. The executor does not need the accepted answers in order to perform the task.

This reduces the chance that verification becomes repetition of expected output instead of independent evidence.

## 9. Selection is not usefulness

A rule entering the prompt proves only that deterministic selection included it.

It does not prove that the model:

- read it;
- applied it;
- needed it;
- benefited from it.

Recall records selection evidence separately from task-local outcomes such as `applied`, `helpful`, `irrelevant`, `harmful`, `not_used`, and `unknown`.

Outcome history may inform later human or Learning-layer decisions, but it does not automatically promote, rewrite, weaken, or retire durable guidance.

See [Guidance event history](guidance-events.md).

## 10. Artifact identity is not lifecycle authority

Owning an artifact format does not grant ownership of every decision made about that artifact.

For example, Recall owns the blind-spot review report representation and can expose the SHA-256 identity of the exact persisted JSON bytes. That proves **which report** a lifecycle host inspected.

It does not prove:

- that the report was acknowledged;
- that findings were accepted;
- that implementation may close;
- that durable Learning should change.

Those decisions remain with the lifecycle or Learning owner.

The same principle applies broadly:

```text
artifact existence ≠ artifact acceptance
review completion   ≠ workflow close-out
context ownership   ≠ task ownership
selection           ≠ usefulness
fact discovery      ≠ source authority
```

See [Public PHP API](public-api.md) and [Embedding Recall](embedding.md).

## 11. Learning must earn durability

Temporary task context should not become permanent project policy merely because an agent encountered it once.

Useful durable knowledge describes something that should materially change how a competent future agent approaches similar work. Task-specific narrative usually should not survive.

Recall therefore records evidence for later Learning decisions while keeping durable mutation outside the compiler's authority boundary.

Forgetting low-value task history is part of context quality, not a failure of memory.

## 12. Fail closed instead of inventing coherence

Compilation should stop when selected input cannot be trusted as one coherent instruction set.

Examples include malformed schemas, conflicting active guidance, stale bindings, contradictory constraints, unknown constraint engines, or missing required validation commands.

Conversely, an empty selection is valid. Recall must not invent synthetic `none` guidance or fake usage evidence just to make output look complete.

The intended behavior is simple:

> preserve uncertainty and missing evidence until real evidence resolves them.

## Authority summary

Recall owns:

```text
bounded context composition
selection and exclusion provenance
L1/L2 prompt semantics owned by Recall
validation briefing
Recall artifact rendering and identity
outcome evidence preparation
```

Recall does not own merely because it has relevant data:

```text
task approval
source-code execution
Run / Session lifecycle
review acknowledgement
final implementation acceptance
durable Learning policy
```

That narrow boundary is intentional. A component that knows useful facts should not gradually become the authority for every state transition around those facts.
