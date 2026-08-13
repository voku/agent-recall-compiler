<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use voku\AgentRecallCompiler\Cli;
use voku\AgentRecallCompiler\Reflection\FutureWorkPromptBuilder;
use voku\AgentRecallCompiler\Reflection\FutureWorkScope;

final class FutureWorkPromptTest extends TestCase
{
    public function testProjectReflectionStaysContextLightAndOpenEnded(): void
    {
        $prompt = (new FutureWorkPromptBuilder())->build(FutureWorkScope::PROJECT);

        self::assertStringContainsString('future work in this project meaningfully better', $prompt);
        self::assertStringContainsString('one highest-leverage direction', $prompt);
        self::assertStringContainsString('nothing worthwhile emerged', $prompt);
        self::assertStringNotContainsString('## Goal', $prompt);
        self::assertStringNotContainsString('system.md', $prompt);
        self::assertLessThan(1500, strlen($prompt));
    }

    public function testTaskReflectionCanReturnToReviewWithoutManufacturingWork(): void
    {
        $prompt = (new FutureWorkPromptBuilder())->build(FutureWorkScope::TASK);

        self::assertStringContainsString("current task's stated completion bar has been met", $prompt);
        self::assertStringContainsString('RETURN_TO_REVIEW', $prompt);
        self::assertStringContainsString('nothing worthwhile emerged', $prompt);
        self::assertStringContainsString('Do not manufacture extra work', $prompt);
        self::assertStringNotContainsString('## Done When', $prompt);
        self::assertLessThan(1500, strlen($prompt));
    }

    public function testUnknownReflectionScopeFailsClosed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Expected project or task');

        (new FutureWorkPromptBuilder())->buildFromString('everything');
    }

    public function testCliAcceptsBothReflectionScopesAndRejectsUnknownScope(): void
    {
        $cli = new Cli();

        self::assertSame(0, $cli->run(['agent-recall-compiler', 'prompt', 'future-work', '--scope', 'project']));
        self::assertSame(0, $cli->run(['agent-recall-compiler', 'prompt', 'future-work', '--scope=task']));
        self::assertSame(1, $cli->run(['agent-recall-compiler', 'prompt', 'future-work', '--scope', 'unknown']));
    }
}
