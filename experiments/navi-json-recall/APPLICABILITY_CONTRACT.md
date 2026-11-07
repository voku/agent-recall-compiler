# Workflow applicability contract v0 (experimental, not accepted)

## Decision scope

This document proposes a falsifiable **selection** rule for the optional
`BoundedChoicePromptBuilder::build(..., enforceWorkflowApplicability: true)`
mode. It is not an authorization rule, an instruction to execute anything,
a replacement for the operating-prompt JSON source, or a production default.

The decision is about selecting **one operating-prompt recipe**, not about
whether a coding agent is capable of completing the underlying technical task.

- **Applicable:** The task asks for a workflow, review method, handoff, or
  engineering discipline whose *operational contract* is materially described
  by a candidate. The task may involve any language or technology; process
  recipes are not limited to the technologies named in their descriptions.
- **Topical overlap only:** The task mentions software implementation, testing,
  or reviewing but does not ask for the process/method represented by a
  candidate. Generic recipe availability alone is insufficient.
- **No matching candidate / ambiguity:** Return `none`. `none` means
  **no recipe selected**; it does not mean the task cannot be completed.
- **Unavailable/invalid model response:** The PHP validator fails closed.
  Record this separately as a transport/format failure, never as a correct
  semantic abstention.
- **Source and authority:** Every candidate must already be supplied by the
  authoritative owner API. Selection never grants permissions, approval,
  workflow mutation, or execution authority.

## Boundary examples (illustrative, not evaluation labels)

- "Develop a dashboard in the existing codebase." on its own does not demand
  a three-pass execution **prompt recipe**.
- "Generate an execution prompt that implements, audits, then simplifies this
  dashboard change" can match a generic three-pass recipe regardless of stack.
- "Design and implement a rate limiter" does not automatically request an
  adversarial review recipe merely because security is relevant.
- "Challenge our rate limiter with concrete bypass hypotheses" explicitly
  requests an adversarial review process.
- "Explain a recipe selection for a household task" is not an engineering
  workflow and must not be coerced into a coding-recipe choice.

These examples define the proposed **rubric**, not additional index aliases
or labels for any existing benchmark case.

## Empirical protocol

1. Keep the existing 30-, 46-, 26-, and 18-query corpora as **development
   diagnostics**. Do not modify their gold labels to make this contract look
   better. In particular, do not retroactively reclassify the three
   `multi-pass-correctness-simplify` disagreements as successes or failures.
2. Obtain separate pre-registered, independent applicability judgements for
   new cases: **workflow demanded**, **workflow absent**, **ambiguous**, and
   **domain-outside-software**. Capture adjudicator disagreement explicitly.
3. Freeze source JSON, recipe metadata, candidate retrieval policy,
   prompt-builder mode and evaluation criteria *before* opening new test cases.
4. Compare default and opt-in prompt modes on **identical candidate sets**
   using the same actual model/provider/config and separately report:
   candidate Recall@3, selection accuracy conditional on gold being present,
   explicit `none`, empty/invalid responses, out-of-catalog false positives,
   and token/latency cost.
5. Only after an independently evaluated improvement and authorization review
   consider changing the production default. Until then, the flag is opt-in
   and is not used by existing run/replay scripts.

## What the CI tests establish

PHPUnit asserts the opt-in instructions are present and that default prompt
construction remains unchanged. Green CI validates **format and compatibility**.
It does *not* prove that an LLM obeys the rubric, nor that a particular
out-of-catalog label is correct.
