<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Output;

/** Read-only owner projection of the compiled developer briefing artifact. */
final readonly class CompiledRecallBriefing
{
    /**
     * @param non-empty-string $path
     * @param non-empty-string $sha256
     */
    public function __construct(
        public string $path,
        public string $sha256,
        public string $content,
    ) {
    }
}
