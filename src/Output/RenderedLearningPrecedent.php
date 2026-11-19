<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Output;

/**
 * A LearningNote that Recall selected and renders into the task briefing.
 *
 * Hosts surface this so the agent and the approving human actually see the
 * precedent: in a controlled experiment a scope-matched note was compiled into
 * every governed run's briefing, and no agent opened it, because nothing the
 * host returned named it. The guidance is historical precedent, not an
 * instruction, and carries no authority over the task.
 */
final readonly class RenderedLearningPrecedent
{
    /**
     * @param non-empty-string $noteId
     * @param list<string> $matchingTaskFiles
     */
    public function __construct(
        public string $noteId,
        public ?string $patternKey,
        public ?string $title,
        public ?string $guidance,
        public array $matchingTaskFiles,
    ) {
    }

    /**
     * Build from a `learning_precedent` fact payload; null unless Recall renders it.
     *
     * @param array<mixed> $payload
     */
    public static function fromFactPayload(array $payload): ?self
    {
        if (($payload['render'] ?? null) !== true) {
            return null;
        }
        $noteId = $payload['note_id'] ?? null;
        if (!is_string($noteId) || $noteId === '') {
            return null;
        }
        $content = is_array($payload['content'] ?? null) ? $payload['content'] : [];
        $files = is_array($payload['matching_task_files'] ?? null) ? $payload['matching_task_files'] : [];

        return new self(
            $noteId,
            self::nonEmpty($payload['pattern_key'] ?? null),
            self::nonEmpty($payload['title'] ?? null),
            self::nonEmpty($content['guidance'] ?? null),
            array_values(array_filter($files, 'is_string')),
        );
    }

    private static function nonEmpty(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
