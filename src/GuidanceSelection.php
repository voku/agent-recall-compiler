<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler;

/**
 * Typed internal selection record used by the compiler pipeline.
 *
 * This is intentionally additive to the historical EvaluatedGuidance shape: public JSON continues to use the
 * same field names, while internals can distinguish matched files from future scope metadata.
 */
final readonly class GuidanceSelection
{
    /**
     * @param list<string> $matchedScopes
     * @param list<string> $matchedFiles
     */
    public function __construct(
        public string $guidanceId,
        public GuidanceType $type,
        public bool $eligible,
        public bool $selected,
        public ?SelectionReason $selectionReason,
        public ?ExclusionReason $exclusionReason,
        public array $matchedScopes = [],
        public array $matchedFiles = [],
        public ?string $sourceProposal = null,
    ) {
        if ($this->selected && !$this->eligible) {
            throw new \InvalidArgumentException('selected guidance must be eligible');
        }
        if ($this->selected && !$this->selectionReason instanceof SelectionReason) {
            throw new \InvalidArgumentException('selected guidance requires a selection reason');
        }
        if (!$this->selected && !$this->exclusionReason instanceof ExclusionReason) {
            throw new \InvalidArgumentException('excluded guidance requires an exclusion reason');
        }
    }

    public static function fromEvaluatedGuidance(EvaluatedGuidance $guidance): self
    {
        return new self(
            $guidance->guidanceId,
            $guidance->guidanceType,
            $guidance->eligible,
            $guidance->selected,
            $guidance->selectionReason,
            $guidance->exclusionReason,
            [],
            $guidance->taskFiles,
            $guidance->sourceProposal,
        );
    }

    public function toEvaluatedGuidance(): EvaluatedGuidance
    {
        return new EvaluatedGuidance(
            $this->guidanceId,
            $this->type,
            $this->eligible,
            $this->selected,
            $this->selectionReason,
            $this->exclusionReason,
            $this->matchedFiles,
            $this->sourceProposal,
        );
    }
}
