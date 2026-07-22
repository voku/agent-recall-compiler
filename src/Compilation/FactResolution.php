<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Compilation;

final readonly class FactResolution
{
    /**
     * @param list<array<string, mixed>> $facts
     * @param list<array{conflict_key: string, selected_id: string, superseded_ids: list<string>, reason: string}> $decisions
     */
    public function __construct(
        public array $facts,
        public array $decisions,
    ) {
    }
}
