<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Provider;

use voku\AgentRecallCompiler\ConstraintManifest;
use voku\AgentRecallCompiler\RecallGuidance;
use voku\AgentRecallCompiler\RecallRejection;
use voku\AgentRecallCompiler\RecallRetirement;

/**
 * The compatibility fields are deliberately typed. They let the first
 * provider wrap today's learning repository without leaking its filesystem
 * format into the orchestration command.
 */
final readonly class RecallProviderResult
{
    /**
     * @param list<RecallFact> $facts
     * @param list<RecallGuidance> $activeGuidance
     * @param list<RecallRejection> $rejectedGuidance
     * @param list<array<string, mixed>> $outcomes
     * @param list<ConstraintManifest> $constraints
     * @param list<RecallRetirement> $retiredProposals
     */
    public function __construct(
        public string $sourceDigest,
        public array $facts = [],
        public array $activeGuidance = [],
        public array $rejectedGuidance = [],
        public array $outcomes = [],
        public array $constraints = [],
        public array $retiredProposals = [],
    ) {
    }
}
