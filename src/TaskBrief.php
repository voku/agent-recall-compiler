<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler;

final readonly class TaskBrief
{
    /**
     * @param list<string> $files
     * @param list<string> $scopes
     */
    public function __construct(
        public string $id,
        public string $description,
        public array $files,
        public array $scopes = [],
    ) {
    }
}
