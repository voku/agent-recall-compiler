<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentRecallCompiler\RecallPromptBuilder;
use voku\AgentRecallCompiler\RecallResult;
use voku\AgentRecallCompiler\TaskBrief;

/**
 * @internal
 */
final class NavigationFactsRenderingTest extends TestCase
{
    public function testIdenticalNavigationSourceRefsRenderOnceInFirstSeenOrder(): void
    {
        $duplicate = '/repo/.agent-loop/map/php-symbols.json';
        $distinct = '/repo/src/Http.php';
        $facts = [
            ['type' => 'navigation', 'source_ref' => $duplicate],
            ['type' => 'navigation', 'source_ref' => $duplicate],
            ['type' => 'navigation', 'source_ref' => $distinct],
            ['type' => 'navigation', 'source_ref' => $duplicate],
        ];

        $prompt = (new RecallPromptBuilder())->buildSystemMd(
            new TaskBrief(id: 'HTTPFUL-1', description: 'Support QUERY.', files: ['src/Http.php']),
            '',
            new RecallResult([], [], []),
            facts: $facts,
        );

        self::assertSame(1, substr_count($prompt, '- ' . $duplicate));
        self::assertSame(1, substr_count($prompt, '- ' . $distinct));

        $duplicatePosition = strpos($prompt, '- ' . $duplicate);
        $distinctPosition = strpos($prompt, '- ' . $distinct);
        self::assertIsInt($duplicatePosition);
        self::assertIsInt($distinctPosition);
        self::assertLessThan($distinctPosition, $duplicatePosition);
    }
}
