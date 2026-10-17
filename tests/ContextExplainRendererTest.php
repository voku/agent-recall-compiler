<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentRecallCompiler\Rendering\ContextExplainRenderer;

final class ContextExplainRendererTest extends TestCase
{
    public function testOmittedLearningTailDoesNotScaleWithCandidatesConsidered(): void
    {
        $renderer = new ContextExplainRenderer();

        $smallItems = [$this->item('learning-note.rendered', true)];
        $largeItems = $smallItems;
        for ($index = 0; $index < 100; ++$index) {
            $item = $this->item(
                sprintf('learning-note.omitted.%03d', $index),
                false,
                'context_budget',
            );
            if ($index < 5) {
                $smallItems[] = $item;
            }
            $largeItems[] = $item;
        }

        $small = $renderer->render($smallItems);
        $large = $renderer->render($largeItems);

        self::assertStringContainsString('### learning-note.rendered', $large);
        self::assertStringContainsString('**context_budget**: 5', $small);
        self::assertStringContainsString('**context_budget**: 100', $large);
        self::assertStringContainsString('selection-report.json.context_explain', $large);
        self::assertStringNotContainsString('learning-note.omitted.099', $large);
        self::assertLessThan(32, strlen($large) - strlen($small));
    }

    public function testDynamicActiveGuidanceIdsCollapseIntoOneBoundedReason(): void
    {
        $markdown = (new ContextExplainRenderer())->render([
            $this->item('learning-note.first', false, 'covered_by_active_guidance:proposal.active.001'),
            $this->item('learning-note.second', false, 'covered_by_active_guidance:proposal.active.002'),
        ]);

        self::assertStringContainsString('**covered_by_active_guidance**: 2', $markdown);
        self::assertStringNotContainsString('proposal.active.001', $markdown);
        self::assertStringNotContainsString('proposal.active.002', $markdown);
    }

    public function testGuidanceExcludedAsIrrelevantCollapsesButOtherExclusionsStayVisible(): void
    {
        $items = [];
        for ($index = 0; $index < 12; ++$index) {
            $items[] = $this->guidanceItem(sprintf('proposal.irrelevant.%02d', $index), 'no_scope_overlap');
        }
        $items[] = $this->guidanceItem('proposal.other.subject', 'no_subject_match');
        $items[] = $this->guidanceItem('proposal.stale.001', 'stale');
        $items[] = $this->guidanceItem('proposal.conflict.001', 'conflicting');

        $markdown = (new ContextExplainRenderer())->render($items);

        self::assertStringContainsString('**no_scope_overlap**: 12', $markdown);
        self::assertStringContainsString('**no_subject_match**: 1', $markdown);
        self::assertStringNotContainsString('proposal.irrelevant.11', $markdown);
        self::assertStringContainsString('### proposal.stale.001', $markdown);
        self::assertStringContainsString('- **Why not**: stale', $markdown);
        self::assertStringContainsString('### proposal.conflict.001', $markdown);
    }

    /**
     * @return array{
     *     id: string, kind: string, what: string, why: string, how: string, authority: string,
     *     use: string, state: string, selected: bool, source_ref: string|null,
     *     evidence_ids: list<string>, why_not: string
     * }
     */
    private function guidanceItem(string $id, string $whyNot): array
    {
        return [
            'id' => 'guidance:' . $id,
            'kind' => 'guidance_decision',
            'what' => $id,
            'why' => 'RecallDecisionEngine evaluated this guidance for the current task.',
            'how' => 'Deterministic guidance eligibility and task-scope selection.',
            'authority' => 'guidance_selection',
            'use' => 'excluded_guidance_do_not_apply',
            'state' => 'verified',
            'selected' => false,
            'source_ref' => null,
            'evidence_ids' => [],
            'why_not' => $whyNot,
        ];
    }

    /**
     * @return array{
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
    private function item(string $id, bool $selected, ?string $whyNot = null): array
    {
        return [
            'id' => 'learning-precedent:' . $id,
            'kind' => 'learning_precedent',
            'what' => $id,
            'why' => 'Deterministic LearningNote relevance: tag_match.',
            'how' => 'LearningNoteRecallProvider exact path-scope/tag selection over the Learning-owned read projection.',
            'authority' => 'learning_precedent',
            'use' => $selected ? 'historical_precedent_not_instruction' : 'machine_fact_only',
            'state' => 'verified',
            'selected' => $selected,
            'source_ref' => 'agent-learning:' . $id,
            'evidence_ids' => ['finding.real.001'],
            ...($whyNot === null ? [] : ['why_not' => $whyNot]),
        ];
    }
}
