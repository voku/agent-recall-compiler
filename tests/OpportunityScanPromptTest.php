<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentRecallCompiler\Cli;
use voku\AgentRecallCompiler\Reflection\OpportunityScanPromptBuilder;

final class OpportunityScanPromptTest extends TestCase
{
    public function testScanIsDeterministicAndDoesNotPresupposeTaskCompletion(): void
    {
        $builder = new OpportunityScanPromptBuilder();
        $prompt = $builder->build();

        self::assertSame($prompt, $builder->build());
        self::assertStringContainsString('what is the single most valuable opportunity or transferable insight', $prompt);
        self::assertStringContainsString('Do not assume that a task was completed', $prompt);
        self::assertStringContainsString('current conversation, investigation, or work context', $prompt);
        self::assertStringContainsString('adjacent problem, project, workflow', $prompt);
        self::assertStringNotContainsString('## Goal', $prompt);
        self::assertStringNotContainsString('## Done When', $prompt);
        self::assertLessThan(2500, strlen($prompt));
    }

    public function testScanRequestsEvidenceAndCanAbstainWithoutExecutionAuthority(): void
    {
        $prompt = (new OpportunityScanPromptBuilder())->build();

        foreach (['INVESTIGATE', 'PREPARE_FOLLOW_UP', 'REQUEST_EVIDENCE', 'NO_OPPORTUNITY'] as $outcome) {
            self::assertStringContainsString('`' . $outcome . '`', $prompt);
        }

        self::assertStringContainsString('specific evidence anchors', $prompt);
        self::assertStringContainsString('smallest discriminating probe', $prompt);
        self::assertStringContainsString('does not approve work', $prompt);
        self::assertStringContainsString('access or edits across projects', $prompt);
        self::assertStringContainsString('Do not manufacture an opportunity', $prompt);
    }

    public function testCliAcceptsOnlyTheStandaloneCommand(): void
    {
        $cli = new Cli();

        self::assertSame(0, $cli->run(['agent-recall-compiler', 'prompt', 'opportunity-scan']));
        self::assertSame(1, $cli->run(['agent-recall-compiler', 'prompt', 'opportunity-scan', '--scope', 'project']));
        self::assertSame(1, $cli->run(['agent-recall-compiler', 'prompt', 'opportunity-scan', '--unknown']));
    }
}
