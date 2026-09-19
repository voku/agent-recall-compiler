<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler;

use InvalidArgumentException;

/**
 * Bounded inline task input for hosts that already know the concrete task identity.
 *
 * Targets are optional for context-only compilation such as durable handoff prompt
 * construction. This mirrors Recall's standalone inline CLI input without requiring
 * hosts to invent a code target that does not exist.
 */
final readonly class InlineCompileTask
{
    public string $taskId;

    public string $description;

    /** @var list<non-empty-string> */
    public array $targets;

    /** @param list<string> $targets */
    public function __construct(string $taskId, string $description, array $targets)
    {
        $taskId = trim($taskId);
        if ($taskId === '') {
            throw new InvalidArgumentException('taskId must be a non-empty string.');
        }

        $normalizedTargets = [];
        foreach ($targets as $target) {
            $target = trim($target);
            if ($target === '') {
                throw new InvalidArgumentException('targets must contain only non-empty strings.');
            }
            $normalizedTargets[] = $target;
        }
        $normalizedTargets = array_values(array_unique($normalizedTargets));

        $this->taskId = $taskId;
        $this->description = $description;
        $this->targets = $normalizedTargets;
    }
}
