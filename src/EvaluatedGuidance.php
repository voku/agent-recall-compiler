<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler;

final readonly class EvaluatedGuidance
{
    /**
     * @param list<string> $taskFiles
     */
    public function __construct(
        public string $guidanceId,
        public GuidanceType $guidanceType,
        public bool $eligible,
        public bool $selected,
        public ?SelectionReason $selectionReason,
        public ?ExclusionReason $exclusionReason,
        public array $taskFiles,
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

    /**
     * @return array{
     *     guidance_id: string,
     *     guidance_type: string,
     *     eligible: bool,
     *     selected: bool,
     *     selection_reason: string|null,
     *     exclusion_reason: string|null,
     *     task_files: list<string>,
     *     source_proposal?: string
     * }
     */
    public function toArray(): array
    {
        $data = [
            'guidance_id' => $this->guidanceId,
            'guidance_type' => $this->guidanceType->value,
            'eligible' => $this->eligible,
            'selected' => $this->selected,
            'selection_reason' => $this->selectionReason?->value,
            'exclusion_reason' => $this->exclusionReason?->value,
            'task_files' => $this->taskFiles,
        ];
        if ($this->sourceProposal !== null) {
            $data['source_proposal'] = $this->sourceProposal;
        }

        return $data;
    }
}
