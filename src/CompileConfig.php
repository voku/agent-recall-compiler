<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler;

final readonly class CompileConfig
{
    public function __construct(
        public RecallRootConfig $rootConfig,
        public TaskBrief $task,
        public string $outputDir,
        public ?string $compilationId = null,
    ) {
    }
}
