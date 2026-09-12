<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Rendering;

final readonly class ContextExplainRenderer
{
    /**
     * @param list<array{
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
     * }> $items
     */
    public function render(array $items): string
    {
        if ($items === []) {
            return '';
        }

        $lines = [
            '## Context Explain Plan',
            '',
            'These explanations describe **context provenance**, not the implementing agent\'s rationale. Use them to understand why Recall exposed a source, how that relevance was derived, what authority it carries, and what the source may be used for.',
            'The **State** classifies the provenance claim shown here; `VERIFIED` does not mean every statement inside the referenced source is automatically correct.',
            '',
        ];

        /** @var array<string, int> $omittedLearningPrecedents */
        $omittedLearningPrecedents = [];
        foreach ($items as $item) {
            if ($item['kind'] === 'learning_precedent' && !$item['selected']) {
                $reason = $this->omissionReason($item['why_not'] ?? null);
                $omittedLearningPrecedents[$reason] = ($omittedLearningPrecedents[$reason] ?? 0) + 1;

                continue;
            }

            $lines[] = '### ' . $item['what'];
            $lines[] = '- **State**: ' . strtoupper($item['state']);
            $lines[] = '- **Selected**: ' . ($item['selected'] ? 'yes' : 'no');
            $lines[] = '- **Why**: ' . $item['why'];
            $lines[] = '- **How**: ' . $item['how'];
            $lines[] = '- **Authority**: ' . $item['authority'];
            $lines[] = '- **Use**: ' . $item['use'];
            if (isset($item['why_not']) && $item['why_not'] !== '') {
                $lines[] = '- **Why not**: ' . $item['why_not'];
            }
            if ($item['source_ref'] !== null && $item['source_ref'] !== '') {
                $lines[] = '- **Source**: ' . $item['source_ref'];
            }
            if ($item['evidence_ids'] !== []) {
                $lines[] = '- **Evidence IDs**: ' . implode(', ', $item['evidence_ids']);
            }
            $lines[] = '';
        }

        if ($omittedLearningPrecedents !== []) {
            ksort($omittedLearningPrecedents, SORT_STRING);
            $lines[] = '### Omitted Learning Precedents';
            $lines[] = '- **Omitted from runtime context**: ' . array_sum($omittedLearningPrecedents);
            $lines[] = '- **Durable detail**: complete per-precedent provenance remains in `selection-report.json.context_explain`.';
            foreach ($omittedLearningPrecedents as $reason => $count) {
                $lines[] = '- **' . $reason . '**: ' . $count;
            }
            $lines[] = '';
        }

        return rtrim(implode("\n", $lines));
    }

    private function omissionReason(?string $reason): string
    {
        if ($reason === null || trim($reason) === '') {
            return 'unspecified';
        }

        $reason = trim($reason);
        if (str_starts_with($reason, 'covered_by_active_guidance:')) {
            return 'covered_by_active_guidance';
        }

        return $reason;
    }
}
