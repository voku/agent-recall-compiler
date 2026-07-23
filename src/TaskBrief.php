<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler;

final readonly class TaskBrief
{
    /**
     * @param list<string> $files
     * @param list<string> $scopes
     * @param list<string> $nonGoals
     * @param list<string> $validation
     * @param list<string> $tags Project-defined relevance labels (domain, system, capability,
     *        or any other taxonomy a project chooses). Matched against fact/guidance tags
     *        independently of path scope, so relevance is not tied to a directory layout.
     */
    public function __construct(
        public string $id,
        public string $description,
        public array $files,
        public array $scopes = [],
        public array $nonGoals = [],
        public array $validation = [],
        public ?string $status = null,
        public ?int $revision = null,
        public ?string $sourcePath = null,
        public array $tags = [],
    ) {
    }
}
