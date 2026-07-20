<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler;

final readonly class RecallRetirement
{
    /**
     * @param list<string> $scope
     */
    public function __construct(
        public string $id,
        public string $reason,
        public array $scope,
        public string $action,
        public ?string $target,
    ) {
    }
}
