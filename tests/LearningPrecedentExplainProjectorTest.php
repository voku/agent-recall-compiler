<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentRecallCompiler\Context\LearningPrecedentExplainProjector;
use voku\AgentRecallCompiler\RecallGuidance;
use voku\AgentRecallCompiler\RecallResult;

final class LearningPrecedentExplainProjectorTest extends TestCase
{
    public function testExplainsScopeAndTagSelection(): void
    {
        $items = (new LearningPrecedentExplainProjector())->project([
            $this->fact(['scope_match', 'tag_match']),
        ], new RecallResult([], [], []));

        self::assertCount(1, $items);
        self::assertTrue($items[0]['selected']);
        self::assertSame('learning_precedent', $items[0]['authority']);
        self::assertStringContainsString('scope_match', $items[0]['why']);
        self::assertStringContainsString('tag_match', $items[0]['why']);
        self::assertSame(['finding.real.001'], $items[0]['evidence_ids']);
    }

    public function testExplainsActiveGuidanceSuppression(): void
    {
        $guidance = new RecallGuidance(
            id: 'proposal.active.001',
            action: 'ADD',
            targetType: 'memory',
            target: 'MEMORY.md',
            scope: ['src/'],
            old: null,
            new: 'Reviewed directive.',
            reason: 'Reviewed.',
            boundary: null,
            validation: [],
            status: 'approved',
            patternKey: 'pattern.real',
        );

        $items = (new LearningPrecedentExplainProjector())->project([
            $this->fact(['scope_match']),
        ], new RecallResult([$guidance], [], []));

        self::assertFalse($items[0]['selected']);
        self::assertSame('covered_by_active_guidance:proposal.active.001', $items[0]['why_not'] ?? null);
        self::assertSame('machine_fact_only', $items[0]['use']);
    }

    /**
     * @param list<string> $reasons
     * @return array<string, mixed>
     */
    private function fact(array $reasons): array
    {
        return [
            'id' => 'learning-precedent.learning-note.real',
            'type' => 'learning_precedent',
            'authority' => 'learning_precedent',
            'source_ref' => 'agent-learning:learning-note.real',
            'scope' => ['src/'],
            'payload' => [
                'note_id' => 'learning-note.real',
                'pattern_key' => 'pattern.real',
                'title' => 'Real precedent',
                'source_findings' => ['finding.real.001'],
                'evidence_state' => 'current',
                'match_reasons' => $reasons,
                'render' => true,
            ],
        ];
    }
}
