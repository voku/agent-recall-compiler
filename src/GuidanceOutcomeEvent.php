<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler;

final readonly class GuidanceOutcomeEvent
{
    public function __construct(
        public string $id,
        public string $compilationId,
        public string $taskId,
        public string $guidanceId,
        public OutcomeValue $outcome,
        public bool $applied,
        public ?string $comment,
        public string $commit,
        public string $recordedBy,
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
     *     outcome: string,
     *     applied: bool,
     *     comment: string|null,
     *     commit: string,
     *     recorded_by: string,
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
            'outcome' => $this->outcome->value,
            'applied' => $this->applied,
            'comment' => $this->comment,
            'commit' => $this->commit,
            'recorded_by' => $this->recordedBy,
            'recorded_at' => $this->recordedAt,
        ];
    }
}
