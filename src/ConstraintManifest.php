<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler;

final readonly class ConstraintManifest
{
    /**
     * @param list<string> $scope
     * @param list<string> $validationCommands
     */
    public function __construct(
        public string $id,
        public string $engine,
        public string $ruleIdentifier,
        public array $scope,
        public array $validationCommands,
        public string $sourceProposal,
        public string $status,
    ) {
    }
}
