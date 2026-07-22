<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Compilation;

use voku\AgentRecallCompiler\RecallResult;

final readonly class RecallCompilation
{
    /**
     * @param array<string, mixed> $bundle
     * @param list<array<string, mixed>> $facts
     * @param list<array{conflict_key: string, selected_id: string, superseded_ids: list<string>, reason: string}> $factDecisions
     */
    public function __construct(
        public RecallResult $result,
        public CompilationSnapshot $snapshot,
        public array $bundle,
        public array $facts,
        public array $factDecisions,
    ) {
    }

    public function bundleDigest(): string
    {
        return hash('sha256', json_encode($this->bundle, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
