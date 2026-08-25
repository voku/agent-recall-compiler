<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler;

use InvalidArgumentException;

/**
 * Bounded board facts supplied by an embedding host without persisting an
 * orchestration-owned context file. Board parsing and policy stay with the
 * board owner; Recall receives only the stable facts it already consumed.
 */
final readonly class KanbanContextProjection
{
    public function __construct(
        public string $taskId,
        public string $sourcePath,
        public string $sourceRevision,
        public string $title,
        public string $lane,
        public string $status,
        public ?int $priority,
        public string $nextAction,
    ) {
        $this->assertNonEmpty($this->taskId, 'taskId');
        $this->assertNonEmpty($this->sourcePath, 'sourcePath');
        $this->assertNonEmpty($this->sourceRevision, 'sourceRevision');
    }

    /**
     * @return array{
     *   schema_version: '1.0',
     *   task_id: string,
     *   source: array{path: string, revision: string},
     *   card: array{title: string, lane: string, status: string, priority: int|null, next_action: string}
     * }
     */
    public function toArray(): array
    {
        return [
            'schema_version' => '1.0',
            'task_id' => $this->taskId,
            'source' => [
                'path' => $this->sourcePath,
                'revision' => $this->sourceRevision,
            ],
            'card' => [
                'title' => $this->title,
                'lane' => $this->lane,
                'status' => $this->status,
                'priority' => $this->priority,
                'next_action' => $this->nextAction,
            ],
        ];
    }

    private function assertNonEmpty(string $value, string $name): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException($name . ' must be a non-empty string.');
        }
    }
}
