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
Based on what became visible while doing this work, where would you invest additional engineering time to make future work in this project meaningfully better?

Step beyond unfinished work and lessons we should simply retain. Consider friction, assumptions, architecture, tooling, validation, context, workflow, simplification, or investigation. Identify one highest-leverage direction, or say that nothing worthwhile emerged. Do not turn this into a review, a learning rule, a backlog, or an evaluation of this reflection mechanism unless the completed work itself made that the highest-leverage problem.
PROMPT,
            FutureWorkScope::TASK => <<<'PROMPT'
Assume the current task's stated completion bar has been met. If you could invest more time in this exact task, what did we miss or what would be worth examining more deeply?

Stay centered on this task. Consider additional validation, edge cases, simplification, investigation, or missed opportunities that only became visible while doing the work. If you find a real correctness or acceptance gap that means the task should not be considered complete, return `RETURN_TO_REVIEW` and explain the gap. Otherwise identify one optional high-value deepening direction, or say that nothing worthwhile emerged. Do not manufacture extra work merely because more time is hypothetically available.
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
