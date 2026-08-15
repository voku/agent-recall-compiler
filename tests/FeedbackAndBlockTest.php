<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentRecallCompiler\Compilation\RecallCompilation;
use voku\AgentRecallCompiler\Compilation\RecallCompilationService;
use voku\AgentRecallCompiler\FeedbackAssessmentRenderer;
use voku\AgentRecallCompiler\FeedbackParser;
use voku\AgentRecallCompiler\Provider\RecallProvider;
use voku\AgentRecallCompiler\Provider\RecallProviderManifest;
use voku\AgentRecallCompiler\Provider\RecallProviderResult;
use voku\AgentRecallCompiler\RecallCompilationBlockedException;
use voku\AgentRecallCompiler\RecallDecisionEngine;
use voku\AgentRecallCompiler\RecallGuidance;
use voku\AgentRecallCompiler\RecallPromptBuilder;
use voku\AgentRecallCompiler\RecallResult;
use voku\AgentRecallCompiler\RecallRootConfig;
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

    public function testProposal004BlocksWhenSelectedValidationRunnerNoLongerExists(): void
    {
        $root = $this->projectRoot();
        file_put_contents($root . '/tools/project-phpstan-rules.php', "<?php\n");
        $guidance = new RecallGuidance(
            'proposal.2026-08-14.004',
            'REPLACE',
            'memory',
            'Tooling test isolation',
            ['phpstan/Rules/NoInProcessPhpstanRuleTestCaseRule.php', 'tools/project-phpstan-rules.sh', 'phpstan/project-rule-test.neon'],
            'tools/project-phpstan-rules.sh and phpstan/project-rule-test.neon',
            'phpstan/Rules/NoInProcessPhpstanRuleTestCaseRule.php and tools/project-phpstan-rules.sh',
            'Regression fixture from the approved .004 record.',
            'The rule governs where fixtures execute.',
            ['bash tools/project-phpstan-rules.sh'],
            'approved',
        );

        $this->expectException(RecallCompilationBlockedException::class);
        $this->expectExceptionMessage("missing project-local entry point 'tools/project-phpstan-rules.sh'");
        $this->compile($guidance, ['tools'], $root);
    }

    public function testProposal011BlocksWhenSelectedValidationRunnerNoLongerExists(): void
    {
        $root = $this->projectRoot();
        $currentRunner = 'tools/' . 'self-' . 'shape-dogfood.php';
        $staleRunner = 'tools/' . 'self-' . 'shape-dogfood.sh';
        file_put_contents($root . '/' . $currentRunner, "<?php\n");
        $guidance = new RecallGuidance(
            'proposal.2026-08-14.011',
            'ADD',
            'memory',
            'Learning evidence detection',
            [$staleRunner],
            null,
            'Detect learning evidence by finding identity across every findings state directory, not by the directory a finding currently occupies.',
            'Regression fixture from the approved .011 record.',
            'Applies to gates that infer what a change recorded.',
            ['bash ' . $staleRunner],
            'approved',
        );

        $this->expectException(RecallCompilationBlockedException::class);
        $this->expectExceptionMessage("missing project-local entry point '" . $staleRunner . "'");
        $this->compile($guidance, ['tools'], $root);
    }

    public function testCurrentPhpValidationRunnerCompilesNormally(): void
    {
        $root = $this->projectRoot();
        file_put_contents($root . '/tools/current-runner.php', "<?php\n");
        $guidance = new RecallGuidance(
            'guidance.current-runner', 'ADD', 'memory', 'Current runner', ['tools/current-runner.php'], null,
            'Use the current PHP runner.', 'Current fixture.', null, ['php tools/current-runner.php'], 'approved',
        );

        $compilation = $this->compile($guidance, ['tools'], $root);

        self::assertSame('guidance.current-runner', $compilation->result->selectedGuidance[0]->id);
    }

    public function testMissingScopePathWithoutLiteralValidationEntryPointDoesNotBlock(): void
    {
        $root = $this->projectRoot();
        $guidance = new RecallGuidance(
            'guidance.future-scope', 'ADD', 'memory', 'Future scope', ['tools/future-runner.php'], null,
            'Keep the rule available for a future-facing target.', 'Missing scope alone is not liveness evidence.', null,
            ['composer ci'], 'approved',
        );

        $compilation = $this->compile($guidance, ['tools'], $root);

        self::assertCount(1, $compilation->result->selectedGuidance);
    }

    private function projectRoot(): string
    {
        $root = sys_get_temp_dir() . '/agent-recall-stale-' . bin2hex(random_bytes(6));
        if (!mkdir($root . '/tools', 0o775, true) && !is_dir($root . '/tools')) {
            self::fail('Unable to create stale-guidance fixture root.');
        }

        return $root;
    }

    /** @param list<string> $taskFiles */
    private function compile(RecallGuidance $guidance, array $taskFiles, string $root): RecallCompilation
    {
        $provider = new class($guidance) implements RecallProvider {
            public function __construct(private readonly RecallGuidance $guidance)
            {
            }

            public function manifest(): RecallProviderManifest
            {
                return new RecallProviderManifest('stale-guidance-fixture', '1.0', []);
            }

            public function collect(TaskBrief $task, RecallRootConfig $rootConfig): RecallProviderResult
            {
                return new RecallProviderResult(
                    sourceDigest: hash('sha256', $this->guidance->id),
                    activeGuidance: [$this->guidance],
                );
            }
        };

        return (new RecallCompilationService([$provider]))->compile(
            new TaskBrief('ARC-55', 'stale guidance regression', $taskFiles),
            new RecallRootConfig($root . '/learning', $root . '/learning/constraints/active', $root),
        );
    }
}
