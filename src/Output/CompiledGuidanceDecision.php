<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Output;

use voku\AgentRecallCompiler\ExclusionReason;
use voku\AgentRecallCompiler\GuidanceType;
use voku\AgentRecallCompiler\SelectionReason;

final readonly class CompiledGuidanceDecision
{
    /** @param list<string> $taskFiles */
    public function __construct(
        public string $guidanceId,
        public GuidanceType $guidanceType,
        public bool $eligible,
        public bool $selected,
        public ?SelectionReason $selectionReason,
        public ?ExclusionReason $exclusionReason,
        public array $taskFiles,
        public ?string $sourceProposal,
    ) {
    }
}
