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
        $prompts = array_values(array_filter(
            $facts,
            static fn (array $fact): bool => ($fact['type'] ?? null) === 'operating_prompt',
        ));
        if ($prompts === []) {
            return '';
        }

        $l1 = [];
        $l2 = [];
        foreach ($prompts as $fact) {
            $payload = is_array($fact['payload'] ?? null) ? $fact['payload'] : [];
            $level = $payload['level'] ?? null;
            if ($level === 2) {
                $l2[] = $fact;
            } elseif ($level === 1) {
                $l1[] = $fact;
            }
        }

        $sections = [];
        if ($l2 !== []) {
            $sections[] = $this->renderL2($l2, $l1 !== []);
        }
        if ($l1 !== []) {
            $sections[] = $this->renderL1($l1);
        }

        return implode("\n\n", $sections);
    }

    /**
     * @param list<array<string, mixed>> $facts
     */
    private function renderL2(array $facts, bool $hasL1Contracts): string
    {
        $md = [
            '## L2 Operational Prompt Construction',
            '',
            'The selected recipes below are meta-prompts, not implementation instructions. Use them to synthesize project-specific L1 operational prompts from the compiled recall context in this briefing.',
            '',
            'For each recipe, produce exactly these five sections:',
            '1. **Goal** — a concrete outcome with every supplied measurable floor preserved.',
            '2. **Context** — exact project anchors already supported by recall evidence: files, symbols, callers, tests, repository patterns, task state, and known constraints.',
            '3. **Constraints** — invariants and scope boundaries that shrink the search space without inventing new policy.',
            '4. **Verification** — exact repository-supported commands, probes, or other measurement procedures that will test the contract.',
            '5. **Done When** — observable results and stopping conditions that those measurements must produce before success may be claimed.',
            '',
            'Construction rules:',
            '- Prefer repository-specific facts over generic advice. If recall already knows an exact path, symbol, command, or contract, name it.',
            '- Do not leave generic placeholders such as `<file>`, `<test command>`, or "follow best practices" when the compiled context contains a concrete replacement.',
            '- Preserve numeric floors and explicit stop conditions from the selected recipe. Do not weaken them into suggestions.',
            '- Preserve approved acceptance criteria as required outcomes, never as evidence that they are satisfied. The generated contract must account for them without manufacturing a pass verdict from their presence.',
            '- Preserve explicit non-goals and approved scope. Context selected for understanding, dependency analysis, or verification does not grant edit permission and must not silently widen the task.',
            '- Keep Verification and Done When distinct: Verification names how reality is measured; Done When names the acceptable observed result.',
            '- If required verification cannot be performed or observed under the current constraints, keep the result `UNKNOWN` or `BLOCKED` and name the missing evidence. Do not weaken acceptance criteria, scope, or non-goals to manufacture success; changing the approved contract requires a separate approved re-plan.',
            '- Never treat prior model reasoning, model confidence, reviewer consensus, prompt construction, or an unexecuted command as verification.',
            '- Never invent repository commands, tools, APIs, or architectural rules. Mark missing evidence as `UNKNOWN` or make evidence discovery part of the generated Context section.',
            '- Use imperative language. Remove hedges such as "maybe", "try to", "consider", "if possible", and "should probably".',
        ];
        if ($hasL1Contracts) {
            $md[] = '- Keep every direct L1 contract below unchanged. Apply those contracts alongside the generated project-specific L1 prompt during execution.';
        }
        $md[] = '- The L2 pass ends after producing the project-specific L1 prompt. Do not implement the task during prompt construction.';
        $md[] = '';

        foreach ($facts as $fact) {
            $this->appendPrompt($md, $fact, 'L2');
        }

        return rtrim(implode("\n", $md));
    }

    /**
     * @param list<array<string, mixed>> $facts
     */
    private function renderL1(array $facts): string
    {
        $md = [
            '## L1 Operating Contract',
            '',
            'These task-selected instructions are already executable operating contracts. Apply them directly and do not weaken their measurable gates or stopping conditions.',
            '',
        ];

        foreach ($facts as $fact) {
            $this->appendPrompt($md, $fact, 'L1');
        }

        return rtrim(implode("\n", $md));
    }

    /**
     * @param list<string> $md
     * @param array<string, mixed> $fact
     */
    private function appendPrompt(array &$md, array $fact, string $level): void
    {
        $payload = is_array($fact['payload'] ?? null) ? $fact['payload'] : [];
        $id = is_string($payload['prompt_id'] ?? null) ? $payload['prompt_id'] : 'unknown';
        $content = is_string($payload['content'] ?? null) ? trim($payload['content']) : '';
        $sourceRef = is_string($fact['source_ref'] ?? null) ? $fact['source_ref'] : 'unknown';

        $md[] = '### ' . $id . ' (' . $level . ')';
        $md[] = '- **Source**: ' . $sourceRef;
        if ($content !== '') {
            $md[] = '';
            $md[] = $content;
        }
        $md[] = '';
    }
}
