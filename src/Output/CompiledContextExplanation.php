<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Output;

final readonly class CompiledContextExplanation
{
    /**
     * @param list<CompiledConstraintSelection> $constraints
     * @param list<CompiledGuidanceDecision> $guidance
     * @param list<CompiledContextExplainItem> $items
     * @param list<string> $warnings
     * @param array<string, array{selected_count:int, helpful_count:int, irrelevant_count:int, harmful_count:int, violation_detected_count:int}> $outcomeStats
     * @param list<string> $integrityFailures
     */
    public function __construct(
        public string $selectionReportPath,
        public ?string $compilationId,
        public string $bundleSha256,
        public array $constraints,
        public array $guidance,
        public array $items,
        public array $warnings,
        public array $outcomeStats,
        public array $integrityFailures,
    ) {
    }

    public function hasIntegrityFailures(): bool
    {
        return $this->integrityFailures !== [];
    }
}
