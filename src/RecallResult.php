<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler;

final readonly class RecallResult
{
    /**
     * @param list<RecallGuidance> $selectedGuidance
     * @param list<RecallRejection> $selectedRejections
     * @param list<string> $warnings
     * @param list<ConstraintManifest> $selectedConstraints
     */
    public function __construct(
        public array $selectedGuidance,
        public array $selectedRejections,
        public array $warnings,
        public array $selectedConstraints = [],
    ) {
    }
}
