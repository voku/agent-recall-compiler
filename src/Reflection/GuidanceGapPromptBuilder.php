<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Reflection;

final class GuidanceGapPromptBuilder
{
    public function build(): string
    {
        return <<<'PROMPT'
Create a project-specific implementation prompt for the current approved task or specification. Its purpose is to make interpretation visible when governing evidence cannot be followed directly. This is an opt-in diagnostic technique, not a default workflow stage.

The generated prompt must tell the coding agent to implement the approved work and, while working, maintain `implementation-notes.html` as a running human-review artifact. Treat that file as task-local working evidence and do not commit it unless the approved task or harness explicitly requires the artifact. Record only decision points that were not already determined by repository evidence. Keep distinct sections for:

- Design decisions: choices made where the task, specification, or repository guidance was ambiguous.
- Deviations: intentional departures from the stated specification or expected process, with the reason and evidence that made the departure necessary.
- Tradeoffs: plausible alternatives considered and why one was chosen.
- Open questions: unresolved questions that a human may need to confirm or revise.
- Guidance gaps: places where the agent had to infer or guess because the expected authority was absent from usable context, missing, stale, conflicting, or incomplete.

For every guidance gap, require an exact task, file, symbol, command, workflow, or contract anchor; the authority that should have answered the question (`SPEC`, `DOC`, `SKILL`, `WORKFLOW`, `TOOL_CONTRACT`, or repository code/tests); what evidence was actually checked; one evidence-backed failure mode; the interpretation used for the current work; the impact if that interpretation is wrong; and the smallest human decision or source-of-truth improvement that would remove the guess for a future agent.

Use exactly one of these failure modes:

- `AUTHORITY_MISSING`: the intended source of truth does not exist.
- `AUTHORITY_NOT_SURFACED`: an applicable authority is proven to exist, but it was absent from the governed, retrieved, installed, or otherwise usable context available at the decision point.
- `AUTHORITY_STALE`: the authority exists but evidence proves it is outdated for current reality.
- `AUTHORITY_CONFLICTING`: applicable authorities disagree materially.
- `AUTHORITY_INCOMPLETE`: the available authority does not answer the material question precisely enough.

Do not guess that an unseen authority exists. Use `AUTHORITY_NOT_SURFACED` only when evidence proves both that the authority exists and that it was omitted from the usable context at the decision point. Match remediation to the failure mode: for `AUTHORITY_NOT_SURFACED`, prefer fixing manifest, scope, retrieval, installation, or routing of the existing authority rather than manufacturing replacement documentation.

Do not turn routine implementation choices into guidance gaps when existing evidence already determines the answer. Do not use model confidence, prior reasoning, or another agent's agreement as evidence. Do not automatically edit documentation or skills merely because a gap was observed, and do not promote these notes to durable learning automatically.

If an ambiguity would change the approved Goal, acceptance criteria, scope or non-goals, a public contract, a security or safety boundary, or destructive/irreversible behavior, the generated prompt must stop that decision and report `HUMAN_DECISION_REQUIRED` / `BLOCKED` with the exact missing authority instead of silently choosing an interpretation.

At completion, require `implementation-notes.html` to summarize unresolved guidance gaps and candidate documentation, skill, workflow, tool-contract, or context-routing improvements with exact anchors. Do not manufacture a backlog. If no material interpretation or guidance gap occurred, state that explicitly.
PROMPT;
    }
}
