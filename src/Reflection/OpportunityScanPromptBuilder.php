<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Reflection;

final class OpportunityScanPromptBuilder
{
    public function build(): string
    {
        return <<<'PROMPT'
Given what we now know, what is the single most valuable opportunity or transferable insight we have not explicitly considered?

Use the current conversation, investigation, or work context as evidence. Do not assume that a task was completed, that an implementation exists, or that the context belongs to a single repository. Look for a non-obvious connection to an adjacent problem, project, workflow, architectural assumption, simpler alternative, or useful investigation. Distinguish observations from hypotheses and name the specific evidence anchors; do not invent facts about other projects or systems.

Compare any candidate with what is already being addressed by the current objective. Choose only an opportunity with a plausible benefit that would justify its investigation cost. Prefer a single smallest discriminating probe over a backlog, speculative implementation, or a generic suggestion to explore more. If the necessary context is absent, say what evidence would discriminate the hypothesis; do not present an unsupported insight as a finding.

Return exactly one disposition: `INVESTIGATE`, `PREPARE_FOLLOW_UP`, `REQUEST_EVIDENCE`, or `NO_OPPORTUNITY`. For a positive candidate, report the observation, transferable hypothesis, target context and owner (if known), expected leverage, uncertainty, and smallest safe next probe. `NO_OPPORTUNITY` is valid.

This scan provides context-only recommendations. It does not approve work, authorize access or edits across projects, change an existing Goal or Contract, or require a new task. Do not manufacture an opportunity to fill the output.
PROMPT;
    }
}
