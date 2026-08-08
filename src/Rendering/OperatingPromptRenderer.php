<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Rendering;

final readonly class OperatingPromptRenderer
{
    /**
     * @param list<array<string, mixed>> $facts
     */
    public function render(array $facts): string
    {
        $operatingPrompts = array_values(array_filter(
            $facts,
            static fn (array $fact): bool => ($fact['type'] ?? null) === 'operating_prompt',
        ));
        if ($operatingPrompts === []) {
            return '';
        }

        $md = [
            '## Operating Contract',
            'These task-selected instructions are instantiated from versioned prompt manifests. Treat them as execution constraints: satisfy their measurable goal, evidence requirement, and stopping condition before declaring the task done.',
            '',
        ];

        foreach ($operatingPrompts as $fact) {
            $payload = is_array($fact['payload'] ?? null) ? $fact['payload'] : [];
            $id = is_string($payload['prompt_id'] ?? null) ? $payload['prompt_id'] : 'unknown';
            $content = is_string($payload['content'] ?? null) ? trim($payload['content']) : '';
            $sourceRef = is_string($fact['source_ref'] ?? null) ? $fact['source_ref'] : 'unknown';

            $md[] = '### ' . $id;
            $md[] = '- **Source**: ' . $sourceRef;
            if ($content !== '') {
                $md[] = '';
                $md[] = $content;
            }
            $md[] = '';
        }

        return rtrim(implode("\n", $md));
    }
}
