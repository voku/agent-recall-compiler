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
    public function testProjectReflectionUsesHotContextWithoutCreatingBacklogAuthority(): void
    {
        $prompt = (new FutureWorkPromptBuilder())->build(FutureWorkScope::PROJECT);

        self::assertStringContainsString('what would you do next', $prompt);
        self::assertStringContainsString('current context is already loaded', $prompt);
        self::assertStringContainsString('semantic owner', $prompt);
        self::assertStringContainsString('smallest independent follow-up slice', $prompt);
        self::assertStringContainsString('NOW_WORTH_PREPARING', $prompt);
        self::assertStringContainsString('NO_FURTHER_INVESTMENT', $prompt);
        self::assertStringContainsString('Do not manufacture backlog', $prompt);
        self::assertStringContainsString('authority to approve or execute a new task', $prompt);
        self::assertStringNotContainsString('## Goal', $prompt);
        self::assertStringNotContainsString('system.md', $prompt);
        self::assertLessThan(2000, strlen($prompt));
    }

    public function testTaskReflectionCanReturnToReviewWithoutManufacturingWork(): void
    {
        $prompt = (new FutureWorkPromptBuilder())->build(FutureWorkScope::TASK);

        self::assertStringContainsString("current task's stated completion bar has been met", $prompt);
        self::assertStringContainsString('RETURN_TO_REVIEW', $prompt);
        self::assertStringContainsString('NOW_WORTH_PREPARING', $prompt);
        self::assertStringContainsString('NO_FURTHER_INVESTMENT', $prompt);
        self::assertStringContainsString('Do not manufacture extra work', $prompt);
        self::assertStringContainsString('widen the approved Contract', $prompt);
        self::assertStringNotContainsString('## Done When', $prompt);
        self::assertLessThan(2000, strlen($prompt));
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
