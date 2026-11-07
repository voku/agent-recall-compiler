# Opportunity-scan: routing and falsifiable evaluation

This context-light reflection covers an **open exploration** that may surface a transferable insight beyond the current objective. Unlike `next-step`, it does not require a defined Goal/Done When or an observed task outcome. Unlike `future-work --scope project|task`, it does not assume verified completion, a repository, or a single-project horizon.

## Routing cases

The following are **design cases and expected contract selection**, not recorded model responses or a semantic accuracy benchmark.

| Observed situation | Existing best route | Why `opportunity-scan` is or is not appropriate |
| --- | --- | --- |
| Reviewing an external coding-agent workflow reveals a potentially simpler approach applicable to another project; no implementation task exists. | `opportunity-scan` | Cross-context transfer without completed-work or original-task assumptions. |
| Investigating an unsupported architectural hypothesis reveals a pattern that may generalize to a separate system, but evidence is incomplete. | `opportunity-scan` | Request the cheapest discriminating evidence; do not assert that the other system has the same problem. |
| Discussing an organization's access-control model reveals a possible reusable design principle, without a completed PR. | `opportunity-scan` | Conceptual opportunity; no repository or execution Contract needed. |
| A task is incomplete and its existing authorized solution needs another attempt. | `next-step` | Continue or pivot the original objective rather than invent adjacent work. |
| A fully verified repository change offers optional high-leverage improvements within the same project. | `future-work --scope project` | Completed-work and project horizon already fit. |
| A completed task may contain an undiscovered correctness gap. | `future-work --scope task` / review | Acceptance verification takes precedence over speculative transfer. |
| A general conversation produces no grounded transferable insight. | `opportunity-scan` → `NO_OPPORTUNITY` | Abstention is a successful outcome; never create an empty task. |
| A possible insight depends on facts from an inaccessible neighboring repository. | `opportunity-scan` → `REQUEST_EVIDENCE` | Do not invent cross-project state or grant access. |

## Discriminating behavioral checks

These checks require a separately documented **real model invocation** before asserting empirical usefulness:

1. Freeze the same transcript, available evidence and model settings for each prompt. Do not secretly inject extra repository facts into `opportunity-scan`.
2. Compare `next-step`, `future-work --scope project` and `opportunity-scan` on the first three open-exploration cases above.
3. Label separately (a) whether the prompt applies, (b) whether it identifies a genuinely new transferable hypothesis, (c) whether the evidence supports it, and (d) whether it abstains safely when evidence is absent. A fluent answer is not sufficient.
4. Include the negative and existing-best-route controls; a general-purpose scan must not outperform by recommending additional work on every case.
5. Preserve exact model/provider/configuration, prompt bytes, source context, outputs and independent human adjudication. Replays of stored text or static PHPUnit assertions are not evidence of inference quality.

## Implementation and authority limits

The `OpportunityScanPromptBuilder` and CLI command only render a stable text lens. They do not invoke an LLM, select an operating recipe, create task state, cross authorization boundaries or change `agent-loop` workflow policy. The caller owns whether to use the advice, and the relevant owner must authorize any subsequent investigation or implementation.

This is intentionally **not** a mandatory task stage, automatic follow-up planner, scoring framework, or recommendation engine. Use the narrower existing primitives whenever their premises match.
