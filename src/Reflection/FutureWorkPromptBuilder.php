<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Reflection;

use InvalidArgumentException;

final class FutureWorkPromptBuilder
{
    public function build(FutureWorkScope $scope): string
    {
        return match ($scope) {
            FutureWorkScope::PROJECT => <<<'PROMPT'
Assume the current task is already complete by observed validation and review evidence. While the repository context, changed code, rejected hypotheses, and architectural reasoning are still fresh, ask: if you could invest additional engineering time now, what would you do next?

Look only for adjacent opportunities whose leverage is unusually high because the current context is already loaded. Consider friction, assumptions, architecture, tooling, validation, simplification, or investigation. Ground every candidate in concrete repository evidence and name the semantic owner, prerequisite, expected leverage, context that would otherwise be lost, validation approach, and smallest independent follow-up slice.

Classify the strongest candidate as `NOW_WORTH_PREPARING`, `FOLLOW_UP`, `BLOCKED`, or `NOT_WORTH_IT`. `NO_FURTHER_INVESTMENT` is a valid result. Prefer one highest-leverage direction over a broad wishlist. Do not manufacture backlog merely because time is available, reopen disproved hypotheses without new evidence, widen the completed task's Contract, or treat this reflection as authority to approve or execute a new task.
PROMPT,
            FutureWorkScope::TASK => <<<'PROMPT'
Assume the current task's stated completion bar has been met by observed validation and review evidence. If you could invest more time in this exact task while its context is still fresh, what did we miss or what would be worth examining more deeply?

Stay centered on this task. Consider additional validation, edge cases, simplification, investigation, or missed opportunities that only became visible while doing the work. Ground the conclusion in concrete current evidence. If you find a real correctness or acceptance gap that means the task should not be considered complete, return `RETURN_TO_REVIEW` and explain the exact gap. Otherwise classify the strongest optional deepening direction as `NOW_WORTH_PREPARING`, `FOLLOW_UP`, `BLOCKED`, or `NOT_WORTH_IT`, or return `NO_FURTHER_INVESTMENT`.

Do not manufacture extra work merely because more time is hypothetically available, reopen disproved hypotheses without new evidence, widen the approved Contract, or treat this reflection as authority to approve follow-up work.
PROMPT,
        };
    }

    public function buildFromString(string $scope): string
    {
        $parsedScope = FutureWorkScope::tryFrom($scope);
        if ($parsedScope === null) {
            throw new InvalidArgumentException(
                sprintf('Unknown future-work scope "%s". Expected project or task.', $scope),
            );
        }

        return $this->build($parsedScope);
    }
}
