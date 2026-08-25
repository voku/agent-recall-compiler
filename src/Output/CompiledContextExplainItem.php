<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Output;

final readonly class CompiledContextExplainItem
{
    /** @param list<string> $evidenceIds */
    public function __construct(
        public string $id,
        public string $kind,
        public string $what,
        public string $why,
        public string $how,
        public string $authority,
        public string $use,
        public ContextExplainState $state,
        public bool $selected,
        public ?string $sourceRef,
        public array $evidenceIds,
        public ?string $whyNot,
    ) {
    }
}
