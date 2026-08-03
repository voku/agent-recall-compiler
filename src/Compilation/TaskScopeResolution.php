<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Compilation;

use voku\AgentRecallCompiler\TaskBrief;

final readonly class TaskScopeResolution
{
    /**
     * @param list<string> $explicitFiles
     * @param list<string> $derivedFiles
     * @param list<string> $derivedFrom
     */
    public function __construct(
        public TaskBrief $effectiveTask,
        public array $explicitFiles,
        public array $derivedFiles,
        public array $derivedFrom,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'explicit_files' => $this->explicitFiles,
            'derived_files' => $this->derivedFiles,
            'files' => $this->effectiveTask->files,
            'derived_from' => $this->derivedFrom,
        ];
    }
}
