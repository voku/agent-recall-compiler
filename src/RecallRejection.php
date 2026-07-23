<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler;

final readonly class RecallRejection
{
    /**
     * @param list<string> $scope
     * @param list<string> $tags
     */
    public function __construct(
        public string $id,
        public string $reason,
        public array $scope,
        public string $action,
        public ?string $target,
        public array $tags = [],
    ) {
    }
}
