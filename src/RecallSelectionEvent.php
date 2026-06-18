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
     *     recorded_at: string
     * }
     */
    public function toArray(): array
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
