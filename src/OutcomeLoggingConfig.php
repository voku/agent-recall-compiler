<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler;

final readonly class OutcomeLoggingConfig
{
    public function __construct(
        public RecallRootConfig $rootConfig,
        public string $draftPath,
        public string $actor,
        public string $commit,
    ) {
    }
}
