<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Rendering;

/**
 * The one ordering of `learning_precedent` facts: evidence state, then scope
 * specificity, then tag overlap, then note id.
 *
 * Compiled facts are persisted in id order, so every consumer that presents
 * precedents must re-rank them through this comparator to match the briefing.
 *
 * @internal
 */
final readonly class LearningPrecedentRanking
{
    /**
     * @param array<mixed> $left fact payload
     * @param array<mixed> $right fact payload
     */
    public static function compare(array $left, array $right): int
    {
        $state = self::stateRank($right['evidence_state'] ?? null) <=> self::stateRank($left['evidence_state'] ?? null);
        if ($state !== 0) {
            return $state;
        }
        $leftSpecificity = is_int($left['scope_specificity'] ?? null) ? $left['scope_specificity'] : 0;
        $rightSpecificity = is_int($right['scope_specificity'] ?? null) ? $right['scope_specificity'] : 0;
        if ($leftSpecificity !== $rightSpecificity) {
            return $rightSpecificity <=> $leftSpecificity;
        }
        $tags = count(self::strings($right['matching_tags'] ?? [])) <=> count(self::strings($left['matching_tags'] ?? []));
        if ($tags !== 0) {
            return $tags;
        }

        return (self::string($left['note_id'] ?? null) ?? '') <=> (self::string($right['note_id'] ?? null) ?? '');
    }

    private static function stateRank(mixed $state): int
    {
        return match (self::string($state)) {
            'current' => 3,
            'review_needed' => 2,
            'no_hashable_repository_evidence' => 1,
            default => 0,
        };
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $strings = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $strings[] = trim($item);
            }
        }

        return array_values(array_unique($strings));
    }
}
