# Design principles

`agent-recall-compiler` is built around one operational assumption: coding agents are fast enough that **context quality, evidence, and authority boundaries** matter more than adding more generic instructions to a prompt.

Recall therefore treats prompting as a compilation problem:

```text
Task intent
+
optional governed Contract binding
+
bounded repository facts
+
relevant guidance and constraints
+
prior outcome evidence
        ↓
     COMPILE
        ↓
project-specific operational context
+ selected prompt recipes
        ↓
receiving agent / harness
        ↓
optional L2 → concrete L1 construction
        ↓
execution
        ↓
verification / review / Learning policy by their proper owners
```

Standalone compilation can start from inline or JSON task input. Governed compilation instead receives a `governed_recall_input` envelope whose `run_id` is bound to one exact approved durable Contract revision and SHA-256 digest.

The specialized documents contain the implementation contracts. This document keeps the common design rules in one place.

## 1. Compile project-specific prompts instead of growing universal prompts

Reusable guidance should normally describe **how to construct** a task-specific operational contract, not hard-code every repository-specific command, path, framework, and historical rule.

Recall distinguishes:

- **L2 recipe**: reusable construction method and quality bar;
- **L1 contract**: concrete executable instructions for the current task.

The target L1 shape stays deliberately small:

```text
Goal
Context
Constraints
Verification
Done When
```

`Goal` defines the observable outcome. `Context` contains current project facts. `Constraints` bound scope and forbidden shortcuts. `Verification` defines how reality is measured. `Done When` defines the stopping condition.

Recall does not itself execute the L2 construction pass. It validates and renders selected recipe semantics next to the compiled evidence in `system.md`; the receiving agent or harness constructs the concrete project-specific L1 contract.

See [Operating prompt recipes](operating-prompts.md).

## 2. Relevant context beats maximum context

More repository content is not automatically better context. Extra input can distract from the task, imply false relevance, widen perceived scope, introduce stale guidance, and consume tokens without improving evidence.

Recall therefore selects from bounded providers and persists the selection decision. Exact targets can use `agent-map` to derive primary source, contracts, change candidates, tests, dependencies, type definitions, blind spots, and omissions.

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

These fields answer different questions:

- **WHAT** is the selected source, command, document, recipe, or decision?
- **WHY** does it matter to the current task?
- **HOW** was that relationship derived?
- **AUTHORITY** identifies what makes the underlying source or claim authoritative.
- **USE** defines what the receiving agent may safely do with it.
- **STATE** records how well the provenance claim is supported.

`HOW` and `AUTHORITY` are deliberately separate. A map may discover a source slice, while the current repository source remains authoritative for the code itself.

See [Context Explain](context-explain.md).

## 4. Context is not edit permission

A file can be required to understand a change without belonging to the approved change.

Typical target-aware usage semantics are:

```text
primary          → implementation candidate
contract         → compatibility contract
change_candidate → inspect and edit if required
verification     → verification context
dependency       → context only
type_definition  → context only
```

Unknown future roles fail closed to context-only semantics until their meaning is verified. Context discovery must not silently expand task scope.

## 5. Evidence state matters more than confidence theater

Material conclusions distinguish:

```text
VERIFIED
INFERRED
ASSUMED
BLOCKED
CONTRADICTED
```

Model confidence, previous rationale, reviewer consensus, prompt construction, and unexecuted commands are not verification. Missing evidence remains unknown or blocked instead of being replaced with plausible prose.

`BLOCKED` is a valid result. A system that cannot represent uncertainty will eventually manufacture certainty.

## 6. Mechanical verification should own mechanical facts

The coding agent should not simultaneously be implementation author, verifier, reviewer, and final authority.

When repository-supported mechanisms can establish a fact, use them: tests, static analysis, mutation tests, benchmarks, schema validation, artifact digests, snapshot identity, or exact changed-file checks.

Recall compiles validation obligations and exact commands when repository evidence supports them. It does not execute those commands during compilation and does not infer success merely because a validation plan exists.

`Verification` and `Done When` remain separate because measurement procedure and acceptance threshold are different contracts.

## 7. Adversarial review generates hypotheses, not automatic truth

First-draft falsification and blind-spot review deliberately search for failure. That posture is useful for generating hypotheses; it does not make a concern true merely because a reviewer can imagine it.

A material concern should move toward evidence by identifying:

```text
claim
evidence
epistemic state
failure chain
earliest observable signal
smallest discriminating probe
why existing verification allowed the failure to escape
```

Dogfood is most useful as a **discriminating experiment**. Depending on the hypothesis, the right probe may be a source checkout, a clean installed consumer, a cross-package release set, a repeat/resume path, or a no-change comparison.

A clean/no-change review result is valid. Finding quotas and numeric review scores are not evidence.

See [Prompt primitives](prompt-primitives.md).

## 8. Keep execution and verifier knowledge separate when useful

When one exact task target is compiled with an `agent-map` index, Recall can build a deterministic verification contract. The execution agent receives public verification questions and obligations, while the separate `verification-key.json` contains canonical accepted answers and source evidence for an independent verifier.

The executor does not need the answer key merely to perform the task. When there is no map index or the task has zero or multiple exact targets, these verification-plan/key artifacts are not generated and stale copies are removed.

This reduces the chance that verification becomes repetition of expected output instead of independent evidence.

## 9. Selection is not usefulness

A rule entering the prompt proves only that deterministic selection included it. It does not prove that the model read it, applied it, needed it, or benefited from it.

Recall records selection evidence separately from task-local outcomes such as `applied`, `helpful`, `irrelevant`, `harmful`, `not_used`, and `unknown`.

Outcome history may inform later human or Learning-layer decisions, but it does not automatically promote, rewrite, weaken, or retire durable guidance.

See [Guidance event history](guidance-events.md).

## 10. Artifact identity is not lifecycle authority

Owning an artifact format does not grant ownership of every decision made about that artifact.

Recall can expose the SHA-256 identity of the exact persisted blind-spot report JSON. That proves **which report** a lifecycle host inspected. It does not prove that the report was acknowledged, findings were accepted, implementation may close, or durable Learning should change.

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

Useful durable knowledge should materially change how a competent future agent approaches similar work. Task-specific narrative usually should not survive.

Recall **does** own a durable evidence append surface: `log-outcome` writes immutable selection, guidance-outcome, and operating-prompt-outcome events under the Learning root with duplicate protection and rollback-safe locking. That evidence is deliberately different from durable Learning policy. Recall does not automatically promote, rewrite, weaken, or retire guidance because an outcome event exists.

Forgetting low-value task history and deciding promotion/retirement policy remain concerns of the Learning layer rather than hidden side effects of compilation.

## 12. Fail closed instead of inventing coherence

Compilation stops when selected input cannot be trusted as one coherent instruction set, for example because of malformed schemas, conflicting guidance, stale bindings, contradictory constraints, unknown engines, or missing required validation commands.

Conversely, an empty selection is valid. Recall must not invent synthetic `none` guidance or fake usage evidence merely to make output look complete.

> Preserve uncertainty and missing evidence until real evidence resolves them.

## Authority summary

Recall owns:

```text
bounded context composition
selection and exclusion provenance
Recall-specific prompt primitive and recipe validation/rendering semantics
validation briefing
Recall artifact rendering and identity
immutable Recall selection/outcome event append semantics
```

Recall does not own merely because it has relevant data:

```text
task approval
source-code execution
Run / Session lifecycle
review acknowledgement
final implementation acceptance
durable Learning promotion / retirement policy
```

That narrow boundary is intentional. A component that knows useful facts should not gradually become the authority for every state transition around those facts.
