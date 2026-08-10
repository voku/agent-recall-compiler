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

        foreach ($items as $item) {
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

        return rtrim(implode("\n", $lines));
    }
}
