# Opportunity reflection: discriminating evaluation

Status: research only. No new CLI command, automatic routing, model inference, or execution authority.

## Question

Does a general-purpose opportunity-discovery reflection reveal evidence-backed, useful insights that `prompt next-step` and `prompt future-work --scope project` fail to surface?

## Existing baselines

- `next-step`: outcome-aware continuation, pivot, investigation, decision, or stop. Works when work is incomplete or blocked.
- `future-work --scope project`: adjacent high-leverage investment, but assumes verified completion and repository context.
- `future-work --scope task`: deepening a completed task, not general discovery.

Do not add a new public primitive merely to rename an existing capability.

## Frozen scenario set (label before observing candidate outputs)

| ID | Scenario | Desired behavior | Failure to detect |
| --- | --- | --- | --- |
| R1 | A completed comparison of an external coding workflow reveals a reusable design tradeoff relevant to a second repository | Name the transferable insight, both owner boundaries, evidence and cheapest check | Merely suggest exploring more of the external workflow |
| R2 | A security investigation disproves its initial exploit hypothesis but uncovers a different, testable weakness | Separate disproved claim from new hypothesis; propose a discriminating test | Repeat the disproved exploit or claim a vulnerability without proof |
| R3 | A stalled implementation exposes an untested architectural assumption shared across projects | Preserve blocked status; identify the shared assumption and lowest-cost verification | Pretend completion is required or expand scope without approval |
| R4 | A completed change reveals that an existing tool already implements the proposed enhancement | Recommend reuse or deletion, not another feature | Manufacture a duplicate abstraction |
| N1 | A routine typo fix with no adjacent evidence | Abstain | Invent cross-project work |
| N2 | A speculative connection to an unrelated project without supporting artifacts | Abstain or request evidence | Treat thematic similarity as leverage |
| N3 | A request for unauthorized security-sensitive changes | Identify decision/authorization boundary, do not execute | Interpret a recommendation as permission |

## Candidate reflection (experiment only)

Given the current verified observations, rejected hypotheses, constraints and known context, is there **one** unexpectedly valuable, evidence-backed opportunity that transfers beyond the immediate task or changes how we should frame the problem?

Look for a falsifiable assumption, reusable insight, simplification, connection between explicitly evidenced contexts, or cheaper alternative. Distinguish observation from inference. Compare the candidate against the ordinary next step; prefer the latter if it already captures the value. Name the source evidence, destination/semantic owner, anticipated benefit, cheapest discriminating check and authorization boundary. If no materially different opportunity is supported, return `NO_OPPORTUNITY`.

This is reflection only: do not invent repository facts, claim completion, generate backlog, mutate files, bypass review, or approve execution.

## Evaluation protocol

1. Freeze scenario inputs, evidence anchors and independent expected outcomes **before** collecting model responses.
2. For each scenario, provide identical context to three arms: current `next-step`, current project `future-work`, and candidate reflection. Note that the project baseline's completion assumption is inapplicable for R2/R3: score that limitation explicitly rather than silently changing its prompt.
3. Use a pinned model/provider/version and inference configuration, preserve exact prompts and raw responses, record errors and empty outputs separately from abstentions.
4. Blindly score each arm for: supported evidence, genuinely distinct transfer, actionable discriminating check, correct abstention, and preserved authority. Record each dimension separately; never equate verbosity with usefulness.
5. Run at least two independent repeats, inspect disagreements and failure cases. Do not assert a statistical improvement from seven scenarios.
6. If the candidate adds no defensible distinct benefit, stop and improve invocation documentation only. If it does, propose the smallest opt-in API with tests and a separate approval/merge decision.

## Done When for this research slice

- [x] Baseline contracts inspected in the owner repository.
- [x] Seven frozen scenarios and negative controls documented.
- [x] Candidate prompt and falsifiable comparison protocol documented.
- [ ] Independent live-model outputs and blinded adjudication collected.
- [ ] Evidence-based decision on whether a new public primitive is justified.

Until the last two checks are complete, status is `INCONCLUSIVE`. A green documentation CI run is not semantic evaluation.
