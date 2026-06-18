<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler;

final readonly class SelectionResult
{
    /**
     * @param list<RecallGuidance> $selectedGuidance
     * @param list<RecallRejection> $selectedRejections
     * @param list<string> $warnings
     * @param list<ConstraintManifest> $selectedConstraints
     * @param array<string, array{selected_count: int, helpful_count: int, irrelevant_count: int, harmful_count: int, violation_detected_count: int}> $outcomeStats
     * @param list<GuidanceSelection> $guidanceSelections
     */
    public function __construct(
        public array $selectedGuidance,
        public array $selectedRejections,
        public array $warnings,
        public array $selectedConstraints = [],
        public array $outcomeStats = [],
        public array $guidanceSelections = [],
    ) {
    }

    public static function fromRecallResult(RecallResult $result): self
    {
        return new self(
            $result->selectedGuidance,
            $result->selectedRejections,
            $result->warnings,
            $result->selectedConstraints,
            $result->outcomeStats,
            array_map(static fn(EvaluatedGuidance $guidance): GuidanceSelection => GuidanceSelection::fromEvaluatedGuidance($guidance), $result->evaluatedGuidance),
        );
    }

    public function toRecallResult(): RecallResult
    {
        return new RecallResult(
            $this->selectedGuidance,
            $this->selectedRejections,
            $this->warnings,
            $this->selectedConstraints,
            $this->outcomeStats,
            array_map(static fn(GuidanceSelection $guidance): EvaluatedGuidance => $guidance->toEvaluatedGuidance(), $this->guidanceSelections),
        );
    }
}
