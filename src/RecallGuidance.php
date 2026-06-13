<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler;

final readonly class RecallGuidance
{
    /**
     * @param list<string> $scope
     * @param list<string> $validation
     */
    public function __construct(
        public string $id,
        public string $action,
        public ?string $targetType,
        public ?string $target,
        public array $scope,
        public ?string $old,
        public ?string $new,
        public string $reason,
        public ?string $boundary,
        public array $validation,
        public string $status,
    ) {
    }
}
