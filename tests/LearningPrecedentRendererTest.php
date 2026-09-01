<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentRecallCompiler\RecallGuidance;
use voku\AgentRecallCompiler\RecallResult;
use voku\AgentRecallCompiler\Rendering\LearningPrecedentRenderer;

final class LearningPrecedentRendererTest extends TestCase
{
    public function testRendersExplicitLowAuthorityPrecedentSection(): void
    {
        $markdown = (new LearningPrecedentRenderer())->render([
            $this->fact('learning-note.git', 'git.repository.state', render: true),
        ], new RecallResult([], [], []));

        self::assertStringContainsString('## Relevant Learning Precedents', $markdown);
        self::assertStringContainsString('not instructions', $markdown);
        self::assertStringContainsString('cannot override', $markdown);
        self::assertStringContainsString('Failed approach from prior case', $markdown);
        self::assertStringContainsString('finding.real.001', $markdown);
    }

    public function testSamePatternSelectedGuidanceSuppressesFullPrecedentProse(): void
    {
        $guidance = new RecallGuidance(
            id: 'proposal.active.001',
            action: 'ADD',
            targetType: 'memory',
            target: 'MEMORY.md',
            scope: ['src/'],
            old: null,
            new: 'Ask Git for repository state.',
            reason: 'Reviewed durable guidance.',
            boundary: null,
            validation: [],
            status: 'approved',
            tags: [],
            patternKey: 'git.repository.state',
        );

        $markdown = (new LearningPrecedentRenderer())->render([
            $this->fact('learning-note.git', 'git.repository.state', render: true),
        ], new RecallResult([$guidance], [], []));

        self::assertStringContainsString('covered_by_active_guidance', $markdown);
        self::assertStringContainsString('proposal.active.001', $markdown);
        self::assertStringNotContainsString('Prior case guidance body.', $markdown);
        self::assertStringNotContainsString('Never infer repository state.', $markdown);
    }

    public function testReviewNeededAndBudgetOmissionStayCompact(): void
    {
        $markdown = (new LearningPrecedentRenderer())->render([
            $this->fact('learning-note.stale', 'pattern.stale', render: false, state: 'review_needed', omissionReason: 'review_needed'),
            $this->fact('learning-note.budget', 'pattern.budget', render: false, omissionReason: 'context_budget'),
        ], new RecallResult([], [], []));

        self::assertStringContainsString('historical precedent with `review_needed`', $markdown);
        self::assertStringContainsString('omitted from full prose by deterministic precedent context budget', $markdown);
        self::assertStringNotContainsString('Prior case guidance body.', $markdown);
    }

    /** @return array<string, mixed> */
    private function fact(
        string $noteId,
        string $patternKey,
        bool $render,
        string $state = 'current',
        ?string $omissionReason = null,
    ): array {
        return [
            'id' => 'learning-precedent.' . $noteId,
            'type' => 'learning_precedent',
            'authority' => 'learning_precedent',
            'source_ref' => 'agent-learning:' . $noteId,
            'scope' => ['src/'],
            'conflict_key' => null,
            'priority' => 0,
            'lifecycle' => $state === 'current' ? 'active' : 'historical',
            'payload' => [
                'note_id' => $noteId,
                'pattern_key' => $patternKey,
                'title' => 'Ask Git for repository state',
                'content' => $render ? [
                    'context' => 'Prior case context.',
                    'guidance' => 'Prior case guidance body.',
                    'why_it_works' => 'Git owns repository truth.',
                    'when_to_apply' => 'Repository detection.',
                    'when_not_to_apply' => 'Shape-specific tests.',
                    'verification' => 'Run current tests.',
                    'failed_approaches' => ['Never infer repository state.'],
                ] : [],
                'source_findings' => ['finding.real.001'],
                'source_proposals' => [],
                'note_digest' => str_repeat('a', 64),
                'evidence_state' => $state,
                'matching_task_files' => ['src/File.php'],
                'matching_tags' => [],
                'match_reasons' => ['scope_match'],
                'scope_specificity' => 3,
                'render' => $render,
                'omission_reason' => $omissionReason,
            ],
        ];
    }
}
