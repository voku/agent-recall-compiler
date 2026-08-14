<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler;

final readonly class RecallSelectionEvent
{
    /**
     * @param list<string> $taskFiles
     */
    public function __construct(
        public string $id,
        public string $compilationId,
        public string $taskId,
        public string $guidanceId,
        public GuidanceType $guidanceType,
        public bool $eligible,
        public bool $selected,
        public ?SelectionReason $selectionReason,
        public ?ExclusionReason $exclusionReason,
        public array $taskFiles,
        public string $recordedAt,
        /**
         * Why this selection was deliberately left unjudged, when it was.
         *
         * The reason is stated at log time and would otherwise be validated and
         * then discarded, leaving a downstream reader unable to tell a declared
         * absence from a dropped one. It rides the selection event because the
         * selection is the thing that went unjudged, and because that record is
         * durable and joins by compilation.
         */
        public ?string $outcomeWithheldReason = null,
    ) {
    }

    /**
     * @return array{
     *     schema_version: '1.0',
     *     id: string,
     *     compilation_id: string,
     *     task_id: string,
     *     guidance_id: string,
     *     guidance_type: string,
     *     eligible: bool,
     *     selected: bool,
     *     selection_reason: string|null,
     *     exclusion_reason: string|null,
     *     task_files: list<string>,
     *     recorded_at: string,
     *     outcome_withheld_reason?: string
     * }
     */
    public function toArray(): array
    {
        if ($this->outcomeWithheldReason !== null) {
            return [...$this->baseArray(), 'outcome_withheld_reason' => $this->outcomeWithheldReason];
        }

        return $this->baseArray();
    }

    /**
     * @return array{
     *     schema_version: '1.0',
     *     id: string,
     *     compilation_id: string,
     *     task_id: string,
     *     guidance_id: string,
     *     guidance_type: string,
     *     eligible: bool,
     *     selected: bool,
     *     selection_reason: string|null,
     *     exclusion_reason: string|null,
     *     task_files: list<string>,
     *     recorded_at: string
     * }
     */
    private function baseArray(): array
    {
        return [
            'schema_version' => '1.0',
            'id' => $this->id,
            'compilation_id' => $this->compilationId,
            'task_id' => $this->taskId,
            'guidance_id' => $this->guidanceId,
            'guidance_type' => $this->guidanceType->value,
            'eligible' => $this->eligible,
            'selected' => $this->selected,
            'selection_reason' => $this->selectionReason?->value,
            'exclusion_reason' => $this->exclusionReason?->value,
            'task_files' => $this->taskFiles,
            'recorded_at' => $this->recordedAt,
        ];
    }
}
