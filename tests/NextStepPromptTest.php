<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentRecallCompiler\Cli;
use voku\AgentRecallCompiler\Reflection\NextStepPromptBuilder;

final class NextStepPromptTest extends TestCase
{
    public function testReflectionHandlesObservedOutcomesWithoutAssumingSuccess(): void
    {
        $prompt = (new NextStepPromptBuilder())->build();

        foreach (['DONE', 'INCOMPLETE', 'BLOCKED', 'INCONCLUSIVE'] as $state) {
            self::assertStringContainsString('`' . $state . '`', $prompt);
        }

        self::assertStringContainsString('So what would you like to do next?', $prompt);
        self::assertStringContainsString('original Goal, Done When, scope, and current evidence', $prompt);
        self::assertStringContainsString('exhausted hypotheses', $prompt);
        self::assertStringContainsString('cheapest discriminating check', $prompt);
        self::assertStringContainsString('no useful next step', $prompt);
        self::assertLessThan(2200, strlen($prompt));
    }

    public function testReflectionSeparatesNextStepCandidatesFromExecutionAuthority(): void
    {
        $prompt = (new NextStepPromptBuilder())->build();

        foreach ([
            'CONTINUE_AUTHORIZED',
            'TEST_HYPOTHESIS',
            'PIVOT_APPROACH',
            'PREPARE_FOLLOW_UP',
            'REQUEST_DECISION',
            'NO_USEFUL_NEXT_STEP',
        ] as $decision) {
            self::assertStringContainsString('`' . $decision . '`', $prompt);
        }

        self::assertStringContainsString('which owner must authorize it', $prompt);
        self::assertStringContainsString('not approval or execution authority', $prompt);
        self::assertStringContainsString('repeat failed attempts without a new discriminating signal', $prompt);
    }

    public function testCliRendersNextStepPromptAndRejectsUnexpectedOptions(): void
    {
        $cli = new Cli();

        self::assertSame(0, $cli->run(['agent-recall-compiler', 'prompt', 'next-step']));
        self::assertSame(1, $cli->run(['agent-recall-compiler', 'prompt', 'next-step', '--scope', 'project']));
    }
}
