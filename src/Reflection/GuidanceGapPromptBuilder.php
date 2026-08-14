<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Reflection;

final class GuidanceGapPromptBuilder
{
    public function build(): string
    {
        return <<<'PROMPT'
Create a project-specific implementation prompt for the current approved task or specification. Its purpose is to make interpretation visible when the governing evidence is missing, stale, conflicting, or too vague for the coding agent to follow the intended process directly. This is an opt-in diagnostic technique, not a default workflow stage.

The generated prompt must tell the coding agent to implement the approved work and, while working, maintain `implementation-notes.html` as a running human-review artifact. Treat that file as task-local working evidence and do not commit it unless the approved task or harness explicitly requires the artifact. Record only decision points that were not already determined by repository evidence. Keep distinct sections for:

- Design decisions: choices made where the task, specification, or repository guidance was ambiguous.
- Deviations: intentional departures from the stated specification or expected process, with the reason and evidence that made the departure necessary.
- Tradeoffs: plausible alternatives considered and why one was chosen.
- Open questions: unresolved questions that a human may need to confirm or revise.
- Guidance gaps: places where the agent had to infer or guess because the expected source of authority was missing, stale, conflicting, misleading, or too vague.

For every guidance gap, require an exact task, file, symbol, command, workflow, or contract anchor; the authority that should have answered the question (`SPEC`, `DOC`, `SKILL`, `WORKFLOW`, `TOOL_CONTRACT`, or repository code/tests); what evidence was actually checked; what was missing or conflicting; the interpretation used for the current work; the impact if that interpretation is wrong; and the smallest human decision or source-of-truth improvement that would remove the guess for a future agent.

Do not turn routine implementation choices into guidance gaps when existing evidence already determines the answer. Do not use model confidence, prior reasoning, or another agent's agreement as evidence. Do not automatically edit documentation or skills merely because a gap was observed, and do not promote these notes to durable learning automatically.

If an ambiguity would change the approved Goal, acceptance criteria, scope or non-goals, a public contract, a security or safety boundary, or destructive/irreversible behavior, the generated prompt must stop that decision and report `HUMAN_DECISION_REQUIRED` / `BLOCKED` with the exact missing authority instead of silently choosing an interpretation.

At completion, require `implementation-notes.html` to summarize unresolved guidance gaps and candidate documentation, skill, workflow, or tool-contract improvements with exact anchors. Do not manufacture a backlog. If no material interpretation or guidance gap occurred, state that explicitly.
PROMPT;
    }
}
