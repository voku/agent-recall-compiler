<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler;

final readonly class RecallRootConfig
{
    public function __construct(
        public string $root,
        public string $activeConstraintsDir,
    ) {
    }
}
