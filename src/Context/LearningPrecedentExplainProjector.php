<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Context;

use voku\AgentRecallCompiler\RecallResult;

/**
 * @phpstan-type LearningExplainItem array{
 *     id: string,
 *     kind: string,
 *     what: string,
 *     why: string,
 *     how: string,
 *     authority: string,
 *     use: string,
 *     state: string,
 *     selected: bool,
 *     source_ref: string|null,
 *     evidence_ids: list<string>,
 *     why_not?: string
 * }
 */
final readonly class LearningPrecedentExplainProjector
{
    /**
     * @param list<array<string, mixed>> $facts
     * @return list<LearningExplainItem>
     */
    public function project(array $facts, RecallResult $result): array
    {
        $activePatterns = [];
        foreach ($result->selectedGuidance as $guidance) {
            if ($guidance->patternKey !== null && trim($guidance->patternKey) !== '') {
                $activePatterns[trim($guidance->patternKey)] = $guidance->id;
            }
        }

        /** @var list<LearningExplainItem> $items */
        $items = [];
        foreach ($facts as $fact) {
            if (($fact['type'] ?? null) !== 'learning_precedent') {
                continue;
            }
            $payload = is_array($fact['payload'] ?? null) ? $fact['payload'] : [];
            $noteId = $this->string($payload['note_id'] ?? null) ?? $this->string($fact['id'] ?? null) ?? 'unknown';
            $patternKey = $this->string($payload['pattern_key'] ?? null);
            $state = $this->string($payload['evidence_state'] ?? null) ?? 'unknown';
            $render = ($payload['render'] ?? false) === true;
            $whyNot = $this->string($payload['omission_reason'] ?? null);
            if ($patternKey !== null && isset($activePatterns[$patternKey])) {
                $render = false;
                $whyNot = 'covered_by_active_guidance:' . $activePatterns[$patternKey];
            }
            $reasons = $this->strings($payload['match_reasons'] ?? []);
            if ($state === 'review_needed') {
                $reasons[] = 'review_needed';
            }

            $items[] = [
                'id' => 'learning-precedent:' . $noteId,
                'kind' => 'learning_precedent',
                'what' => $this->string($payload['title'] ?? null) ?? $noteId,
                'why' => $reasons === []
                    ? 'The LearningNote owner projection was eligible but exposed no reconstructed relevance reason.'
                    : 'Deterministic LearningNote relevance: ' . implode(', ', array_values(array_unique($reasons))) . '.',
                'how' => 'LearningNoteRecallProvider exact path-scope/tag selection over the Learning-owned read projection.',
                'authority' => 'learning_precedent',
                'use' => $render ? 'historical_precedent_not_instruction' : 'machine_fact_only',
                'state' => $state === 'current' ? 'verified' : 'unknown',
                'selected' => $render,
                'source_ref' => $this->string($fact['source_ref'] ?? null),
                'evidence_ids' => $this->strings($payload['source_findings'] ?? []),
                ...($whyNot === null ? [] : ['why_not' => $whyNot]),
            ];
        }
        usort($items, static fn (array $left, array $right): int => $left['id'] <=> $right['id']);

        return $items;
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** @return list<string> */
    private function strings(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $result = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $result[] = trim($item);
            }
        }

        return array_values(array_unique($result));
    }
}
