<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentRecallCompiler\FeedbackAssessmentRenderer;
use voku\AgentRecallCompiler\FeedbackParser;
use voku\AgentRecallCompiler\RecallCompilationBlockedException;
use voku\AgentRecallCompiler\RecallDecisionEngine;
use voku\AgentRecallCompiler\RecallGuidance;
use voku\AgentRecallCompiler\RecallPromptBuilder;
use voku\AgentRecallCompiler\RecallResult;
use voku\AgentRecallCompiler\TaskBrief;

final class FeedbackAndBlockTest extends TestCase
{
    public function testFeedbackParserReadsJsonStringArray(): void
    {
        $assessment = (new FeedbackParser())->parse('["claim one", "  ", "claim two"]');

        self::assertCount(2, $assessment->items);
        self::assertSame('claim one', $assessment->items[0]->claim);
        self::assertSame('external-agent', $assessment->items[0]->source);
        self::assertSame('claim two', $assessment->items[1]->claim);
    }

    public function testFeedbackParserReadsJsonObjects(): void
    {
        $json = '{"items": [{"source": "reviewer-bot", "claim": "Use the boundary"}, {"text": "Add a test"}]}';
        $assessment = (new FeedbackParser())->parse($json);

        self::assertCount(2, $assessment->items);
        self::assertSame('reviewer-bot', $assessment->items[0]->source);
        self::assertSame('Use the boundary', $assessment->items[0]->claim);
        self::assertSame('Add a test', $assessment->items[1]->claim);
    }

    public function testFeedbackParserReadsPlainTextParagraphs(): void
    {
        $text = "First claim line.\n\nSecond claim paragraph.";
        $assessment = (new FeedbackParser())->parse($text);

        self::assertCount(2, $assessment->items);
        self::assertSame('First claim line.', $assessment->items[0]->claim);
        self::assertSame('Second claim paragraph.', $assessment->items[1]->claim);
    }

    public function testEmptyFeedbackIsEmpty(): void
    {
        self::assertTrue((new FeedbackParser())->parse('   ')->isEmpty());
    }

    public function testFeedbackAssessmentRendererProducesUnverifiedDraft(): void
    {
        $assessment = (new FeedbackParser())->parse('["claim one"]');
        $json = (new FeedbackAssessmentRenderer())->render(new TaskBrief('ITPNG-1', '', []), $assessment, 'compilation.x');
        $data = json_decode($json, true);

        self::assertIsArray($data);
        self::assertSame('untrusted', $data['trust']);
        self::assertSame('ITPNG-1', $data['task_id']);
        self::assertSame('compilation.x', $data['compilation_id']);
        self::assertCount(1, $data['items']);
        self::assertSame('unverified', $data['items'][0]['status']);
        self::assertFalse($data['items'][0]['verified_against_repository']);
        self::assertNull($data['items'][0]['verdict']);
    }

    public function testSystemMdIncludesUnverifiedFeedbackSection(): void
    {
        $result = new RecallResult([], [], []);
        $feedback = (new FeedbackParser())->parse('["Refactor the whole module"]');

        $systemMd = (new RecallPromptBuilder())->buildSystemMd(
            new TaskBrief('ITPNG-1', '', []),
            '',
            $result,
            $feedback,
        );

        self::assertStringContainsString('## Unverified Peer Feedback (Untrusted)', $systemMd);
        self::assertStringContainsString('may be correct or completely wrong', $systemMd);
        self::assertStringContainsString('Refactor the whole module', $systemMd);
        self::assertStringContainsString('feedback-assessment.draft.json', $systemMd);
    }

    public function testSystemMdOmitsFeedbackSectionWhenNoneGiven(): void
    {
        $systemMd = (new RecallPromptBuilder())->buildSystemMd(new TaskBrief('ITPNG-1', '', []), '', new RecallResult([], [], []));

        self::assertStringNotContainsString('Unverified Peer Feedback', $systemMd);
    }

    public function testMetaJsonHasBlockedFieldsDefaultingFalse(): void
    {
        $meta = json_decode(
            (new RecallPromptBuilder())->buildMetaJson(new TaskBrief('ITPNG-1', '', []), new RecallResult([], [], [])),
            true,
        );

        self::assertIsArray($meta);
        self::assertFalse($meta['blocked']);
        self::assertNull($meta['block_reason']);
    }

    public function testMetaJsonCanCarryBlockedReason(): void
    {
        $meta = json_decode(
            (new RecallPromptBuilder())->buildMetaJson(
                new TaskBrief('ITPNG-1', '', []),
                new RecallResult([], [], ['boom']),
                'compilation.x',
                [],
                true,
                'Conflict: duplicate target',
            ),
            true,
        );

        self::assertIsArray($meta);
        self::assertTrue($meta['blocked']);
        self::assertSame('Conflict: duplicate target', $meta['block_reason']);
    }

    public function testConflictRaisesBlockedException(): void
    {
        $activeGuidance = [
            new RecallGuidance('g-1', 'ADD', 'skill', 'auth', ['src/Auth'], null, 'Wording 1', 'Reason 1', 'Boundary 1', [], 'approved'),
            new RecallGuidance('g-2', 'ADD', 'skill', 'auth', ['src/Auth'], null, 'Wording 2', 'Reason 2', 'Boundary 2', [], 'approved'),
        ];

        $this->expectException(RecallCompilationBlockedException::class);
        $this->expectExceptionMessage("Conflict: Multiple active guidance items target 'auth'");

        (new RecallDecisionEngine())->decide(
            new TaskBrief('ITPNG-123', '', ['src/Auth/OAuth.php']),
            $activeGuidance,
            [],
            [],
        );
    }
}
