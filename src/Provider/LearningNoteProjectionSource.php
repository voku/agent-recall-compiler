<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Provider;

interface LearningNoteProjectionSource
{
    /** @return list<LearningNotePrecedentProjection> */
    public function active(string $learningRoot): array;
}
