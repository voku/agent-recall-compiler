<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentRecallCompiler\Cli;
use voku\AgentRecallCompiler\Reflection\GuidanceGapPromptBuilder;
use voku\AgentRecallCompiler\Rendering\OperatingPromptRenderer;
use voku\AgentRecallCompiler\Review\CodeReviewPromptBuilder;
use voku\AgentRecallCompiler\Review\FirstDraftReviewPromptBuilder;
use voku\AgentRecallCompiler\Review\ReviewCli;

final class PromptPrimitiveTest extends TestCase
{
    public function testFirstDraftReviewIsContextLightAndEvidenceBound(): void
    {
        $prompt = (new FirstDraftReviewPromptBuilder())->build();

        self::assertStringContainsString('first draft', $prompt);
        self::assertStringContainsString('falsification rather than confirmation', $prompt);
        self::assertStringContainsString('Acceptance criteria are required outcomes, not evidence', $prompt);
        self::assertStringContainsString('UNKNOWN or BLOCKED', $prompt);
        self::assertStringContainsString('CLEAN is valid only after concrete attempts to falsify', $prompt);
        self::assertStringNotContainsString('## Goal', $prompt);
        self::assertLessThan(1500, strlen($prompt));
    }

    public function testReviewCliPrintsFirstDraftPromptWithoutTaskStateAndRejectsExtraInput(): void
    {
        $workspace = sys_get_temp_dir() . '/agent-recall-first-draft-' . bin2hex(random_bytes(6));
        mkdir($workspace, 0o775, true);

        ob_start();
        try {
            $exit = (new ReviewCli($workspace))->run(['agent-recall-compiler review', 'first-draft']);
            $output = (string) ob_get_clean();
        } catch (\Throwable $throwable) {
            ob_end_clean();
            rmdir($workspace);
            throw $throwable;
        }

        self::assertSame(0, $exit);
        self::assertStringContainsString('falsification rather than confirmation', $output);
        self::assertStringContainsString('Prior reasoning, model confidence', $output);

        self::assertSame(1, (new ReviewCli($workspace))->run([
            'agent-recall-compiler review',
            'first-draft',
            'unexpected',
        ]));

        rmdir($workspace);
    }

    public function testArtifactCodeReviewInheritsFirstDraftFalsificationLens(): void
    {
        $workspace = sys_get_temp_dir() . '/agent-recall-code-review-' . bin2hex(random_bytes(6));
        mkdir($workspace, 0o775, true);

        try {
            $prompt = (new CodeReviewPromptBuilder($workspace))->build('ABC-123', '.agent-recall/current');
        } finally {
            rmdir($workspace);
        }

        self::assertStringStartsWith('# L2 code review prompt for ABC-123', $prompt);
        self::assertStringContainsString('## First-draft falsification lens', $prompt);
        self::assertStringContainsString('CLEAN is valid only after concrete attempts to falsify', $prompt);
        self::assertStringContainsString('Select one dominant installed `code-review-*` engineering lens', $prompt);
    }

    public function testGuidanceGapPromptIsExplicitL2HumanInLoopTechnique(): void
    {
        $prompt = (new GuidanceGapPromptBuilder())->build();

        self::assertStringStartsWith('Create a project-specific implementation prompt', $prompt);
        self::assertStringContainsString('implementation-notes.html', $prompt);
        self::assertStringContainsString('do not commit it unless the approved task or harness explicitly requires the artifact', $prompt);
        self::assertStringContainsString('Design decisions', $prompt);
        self::assertStringContainsString('Deviations', $prompt);
        self::assertStringContainsString('Tradeoffs', $prompt);
        self::assertStringContainsString('Open questions', $prompt);
        self::assertStringContainsString('Guidance gaps', $prompt);
        self::assertStringContainsString('`SPEC`, `DOC`, `SKILL`, `WORKFLOW`, `TOOL_CONTRACT`', $prompt);
        self::assertStringContainsString('HUMAN_DECISION_REQUIRED', $prompt);
        self::assertStringContainsString('Do not automatically edit documentation or skills', $prompt);
        self::assertStringContainsString('do not promote these notes to durable learning automatically', $prompt);
        self::assertLessThan(4000, strlen($prompt));
    }

    public function testCliRunsGuidanceGapPromptOnlyWhenExplicitlyRequested(): void
    {
        self::assertSame(0, (new Cli())->run([
            'agent-recall-compiler',
            'prompt',
            'guidance-gaps',
        ]));

        self::assertSame(1, (new Cli())->run([
            'agent-recall-compiler',
            'prompt',
            'guidance-gaps',
            '--unexpected',
        ]));
    }

    public function testL2ConstructionPreservesAcceptanceScopeAndBlockedEvidenceBoundaries(): void
    {
        $markdown = (new OperatingPromptRenderer())->render([[
            'id' => 'operating_prompt.test',
            'type' => 'operating_prompt',
            'source_ref' => 'manifest:test',
            'payload' => [
                'prompt_id' => 'test-recipe',
                'level' => 2,
                'content' => 'Construct a project-specific test prompt.',
            ],
        ]]);

        self::assertStringContainsString('acceptance criteria as required outcomes, never as evidence', $markdown);
        self::assertStringContainsString('does not grant edit permission', $markdown);
        self::assertStringContainsString('keep the result `UNKNOWN` or `BLOCKED`', $markdown);
        self::assertStringContainsString('Do not weaken acceptance criteria, scope, or non-goals', $markdown);
        self::assertStringContainsString('requires a separate approved re-plan', $markdown);
        self::assertStringContainsString('prompt construction, or an unexecuted command as verification', $markdown);
    }
}
