<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Provider;

interface TaskAwareLearningNoteProjectionSource extends LearningNoteProjectionSource
{
    /**
     * @param list<string> $taskFiles
     * @param list<string> $taskTags
     */
    public function forTaskWithContext(
        string $learningRoot,
        string $taskId,
        ?string $projectRoot = null,
        array $taskFiles = [],
        array $taskTags = [],
    ): LearningTaskPrecedentProjection;
}
