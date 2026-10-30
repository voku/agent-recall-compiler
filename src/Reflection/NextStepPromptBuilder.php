<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Reflection;

final class NextStepPromptBuilder
{
    public function build(): string
    {
        return <<<'PROMPT'
Ask yourself: "So what would you like to do next?"

First re-ground the original Goal, Done When, scope, and current evidence. Classify the actual outcome as `DONE`, `INCOMPLETE`, `BLOCKED`, or `INCONCLUSIVE`. Do not assume success because implementation was attempted or tests passed, and do not turn missing evidence into a claim that no solution exists.

If `DONE`, consider at most one evidence-backed, high-leverage follow-up, or stop. If `INCOMPLETE`, prefer the smallest still-authorized continuation of the original task. If `BLOCKED` or `INCONCLUSIVE`, distinguish constraints and missing decisions from exhausted hypotheses; prefer the cheapest discriminating check or a genuinely different approach over repeating an unproductive attempt. State when no useful next step can be justified.

Choose one outcome: `CONTINUE_AUTHORIZED`, `TEST_HYPOTHESIS`, `PIVOT_APPROACH`, `PREPARE_FOLLOW_UP`, `REQUEST_DECISION`, or `NO_USEFUL_NEXT_STEP`. Report the observed outcome, concrete evidence anchors, remaining unknowns, why the proposed action has leverage, its smallest bounded next step, and which owner must authorize it. `NO_USEFUL_NEXT_STEP` is valid.

This reflection is a recommendation, not approval or execution authority. Do not manufacture work, reopen disproved hypotheses without new evidence, widen the approved Contract, repeat failed attempts without a new discriminating signal, or treat a blocked task as completed.
PROMPT;
    }
}
