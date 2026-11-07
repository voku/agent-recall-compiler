<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentRecallCompiler\Cli;
use voku\AgentRecallCompiler\Reflection\ExplorePromptBuilder;
use voku\AgentRecallCompiler\Reflection\FutureWorkPromptBuilder;
use voku\AgentRecallCompiler\Reflection\FutureWorkScope;
use voku\AgentRecallCompiler\Reflection\NextStepPromptBuilder;

final class ExplorePromptTest extends TestCase
{
    public function testExplorationDoesNotRequireCompletedWorkOrRepositoryContext(): void
    {
        $prompt = (new ExplorePromptBuilder())->build();

        self::assertStringContainsString('Is there anything here worth exploring further?', $prompt);
        self::assertStringContainsString('conversation', $prompt);
        self::assertStringContainsString('research', $prompt);
        self::assertStringContainsString('unfinished task', $prompt);
        self::assertStringContainsString('Do not assume that work is complete', $prompt);
        self::assertStringContainsString('non-mutating', $prompt);
        self::assertLessThan(1800, strlen($prompt));
    }

    public function testExplorationKeepsInquirySeparateFromExecutionAndCanAbstain(): void
    {
        $prompt = (new ExplorePromptBuilder())->build();

        self::assertStringContainsString('`EXPLORE`', $prompt);
        self::assertStringContainsString('`NOTHING_TO_EXPLORE`', $prompt);
        self::assertStringContainsString('observed facts from hypotheses and unknowns', $prompt);
        self::assertStringContainsString('specific evidence anchor', $prompt);
        self::assertStringContainsString('Do not plan or authorize implementation', $prompt);
        self::assertStringContainsString('unapproved implication', $prompt);

        self::assertNotSame($prompt, (new NextStepPromptBuilder())->build());
        self::assertNotSame($prompt, (new FutureWorkPromptBuilder())->build(FutureWorkScope::PROJECT));
    }

    public function testCliExposesExplicitExplorePromptWithoutAcceptingExtraScope(): void
    {
        $cli = new Cli();

        self::assertSame(0, $cli->run(['agent-recall-compiler', 'prompt', 'explore']));
        self::assertSame(1, $cli->run(['agent-recall-compiler', 'prompt', 'explore', '--scope', 'project']));
        self::assertSame(1, $cli->run(['agent-recall-compiler', 'prompt', 'explore', 'work']));
    }
}
