<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Provider;

interface LearningNoteProjectionSource
{
    public function isAvailable(): bool;

    public function forTask(
        string $learningRoot,
        string $taskId,
        ?string $projectRoot = null,
    ): LearningTaskPrecedentProjection;
}
