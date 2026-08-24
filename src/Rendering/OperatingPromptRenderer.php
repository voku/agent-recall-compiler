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
            '- Preserve task acceptance criteria as required outcomes, never as evidence that they are satisfied. The generated contract must account for them without manufacturing a pass verdict from their presence.',
            '- Preserve explicit non-goals and task scope. Context selected for understanding, dependency analysis, or verification does not grant edit permission and must not silently widen the task.',
            '- Keep Verification and Done When distinct: Verification names how reality is measured; Done When names the acceptable observed result.',
            '- If required verification cannot be performed or observed under the current constraints, keep the result `UNKNOWN` or `BLOCKED` and name the missing evidence. Do not weaken acceptance criteria, scope, or non-goals to manufacture success. Changing task policy belongs to its owner; in a governed run that requires a separate approved re-plan.',
            '- Never treat prior model reasoning, model confidence, reviewer consensus, prompt construction, or an unexecuted command as verification.',
            '- Never invent repository commands, tools, APIs, or architectural rules. Mark missing evidence as `UNKNOWN` or make evidence discovery part of the generated Context section.',
            '- Use imperative language. Remove hedges such as "maybe", "try to", "consider", "if possible", and "should probably".',
            '',
            'Delegated execution continuation rules:',
            ...$this->executionContinuationRules(),
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
            'For any selected contract that performs multi-step or delegated execution, also apply these shared continuation rules:',
            ...$this->executionContinuationRules(),
            '',
        ];

        foreach ($facts as $fact) {
            $this->appendPrompt($md, $fact, 'L1');
        }

        return rtrim(implode("\n", $md));
    }

    /** @return list<string> */
    private function executionContinuationRules(): array
    {
        return [
            '- Apply these continuation rules only to contracts that execute work. A durable planning/work-package recipe such as `todo-card-handoff` is not executable authority and must not inherit automatic continuation merely because it is rendered beside execution guidance.',
            '- When the authorized work contains multiple TODOs or milestones, define bounded executable slices before implementation. For each slice name the objective, dependencies, expected change or artifact, and the verification/checkpoint that can justify continuing.',
            '- After each slice, run the relevant available validation and perform an internal continuation check against current task/run/contract authority, observed evidence, remaining dependencies, and blocker scope. This is not approval and must never substitute for a human, owner, reviewer, accepted-risk, destructive, irreversible, or security decision.',
            '- Continue automatically across remaining authorized independent slices when the current authority and evidence still support them. A discovered blocker stops only the affected slice and work that actually depends on it unless every remaining safe slice is transitively blocked.',
            '- When practical, compare failing validation with known baseline evidence and distinguish `PRE_EXISTING`, `INTRODUCED`, and `UNKNOWN_ORIGIN`. Do not attribute an old failure to the current slice or stop unrelated authorized work merely because a baseline gate was already red; unresolved required gates still prevent final success.',
            '- A handoff described as production-ready must not delegate execution when current authoritative evidence already proves a required hard prerequisite is missing, the worker is not authorized to satisfy it, and execution depends on it. Render that handoff `NOT_READY_TO_DELEGATE` with the prerequisite, owner, verification probe, and current evidence instead of spending an execution run to rediscover it.',
            '- Treat executor completion prose as a claim. Before final success, reconcile it with available authoritative artifacts and evidence such as actual head/base/diff, changed files or artifacts, validation results, review findings, and remaining blockers; real evidence wins on disagreement.',
        ];
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
