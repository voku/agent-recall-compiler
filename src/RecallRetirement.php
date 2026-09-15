<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler;

final readonly class RecallRetirement
{
    /**
     * @param list<string> $scope
     * @param list<string> $tags
     * @param ?string      $supersededBy id of the proposal this one was retired in favour of, if any
     */
    public function __construct(
        public string $id,
        public string $reason,
        public array $scope,
        public string $action,
        public ?string $target,
        public array $tags = [],
        public ?string $supersededBy = null,
    ) {
    }
}
