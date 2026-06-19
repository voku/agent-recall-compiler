<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler;

use RuntimeException;

enum GuidanceType: string
{
    case MEMORY = 'memory';
    case SKILL = 'skill';
    case CONSTRAINT = 'constraint';

    /**
     * Single source of truth for deriving a guidance type from a stored
     * `target_type` string. Legacy "file" targets (e.g. MEMORY.md entries)
     * are projected onto MEMORY. Callers must not re-derive this mapping
     * independently, or compile-time and outcome-logging-time type
     * classification can drift apart and disagree.
     */
    public static function fromTargetType(?string $targetType, string $guidanceId): self
    {
        if ($targetType === 'file') {
            return self::MEMORY;
        }

        if ($targetType === null || trim($targetType) === '') {
            return self::SKILL;
        }

        $type = self::tryFrom($targetType);
        if (!$type instanceof self) {
            throw new RuntimeException(sprintf("guidance '%s' has unknown guidance type '%s'", $guidanceId, $targetType));
        }

        return $type;
    }
}
