<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Output;

final readonly class CompiledConstraintSelection
{
    /**
     * @param list<string>|null $scope
     * @param list<string>|null $validationCommands
     * @param list<string>|null $tags
     */
    public function __construct(
        public string $id,
        public string $engine,
        public string $ruleIdentifier,
        public string $sourceProposal,
        public ?array $scope = null,
        public ?array $validationCommands = null,
        public ?string $status = null,
        public ?array $tags = null,
    ) {
    }

    public function hasExtendedMetadata(): bool
    {
        return $this->scope !== null
            && $this->validationCommands !== null
            && $this->status !== null
            && $this->tags !== null;
    }
}
