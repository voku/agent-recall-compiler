<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Output;

/**
 * A LearningNote that matched the task but was withheld from the rendered
 * briefing because its repository evidence no longer verifies.
 *
 * Suppression is correct fail-closed behavior, but silent: in real use one
 * relevant note was withheld from eight of nine matching tasks while nobody
 * was told. The session that just touched the drifted files is the best judge
 * of whether the lesson still holds, so hosts can surface this as Learning
 * maintenance. It carries no authority to republish or retire the note.
 */
final readonly class SuppressedLearningPrecedent
{
    /**
     * @param list<string> $matchingTaskFiles
     */
    public function __construct(
        public string $noteId,
        public ?string $patternKey,
        public ?string $title,
        public string $evidenceState,
        public array $matchingTaskFiles,
    ) {
    }
}
