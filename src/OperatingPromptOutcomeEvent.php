<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler;

final readonly class OperatingPromptOutcomeEvent
{
    /** @param list<string> $evidence */
    public function __construct(
        public string $id,
        public string $compilationId,
        public string $taskId,
        public string $promptId,
        public OutcomeValue $outcome,
        public bool $applied,
        public array $evidence,
        public ?string $comment,
        public string $commit,
        public string $recordedBy,
        public string $recordedAt,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => '1.0',
            'id' => $this->id,
            'compilation_id' => $this->compilationId,
            'task_id' => $this->taskId,
            'prompt_id' => $this->promptId,
            'outcome' => $this->outcome->value,
            'applied' => $this->applied,
            'evidence' => $this->evidence,
            'comment' => $this->comment,
            'commit' => $this->commit,
            'recorded_by' => $this->recordedBy,
            'recorded_at' => $this->recordedAt,
        ];
    }
}
