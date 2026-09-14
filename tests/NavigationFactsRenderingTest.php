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

    public function testPayloadBackedNavigationFactsRenderExactSymbolsMethodsAndLines(): void
    {
        $facts = [
            [
                'type' => 'navigation',
                'source_ref' => '/repo/.agent-loop/map/php-symbols.json',
                'payload' => [
                    'path' => 'src/Http.php',
                    'symbols' => [
                        [
                            'kind' => 'class',
                            'name' => 'Http',
                            'fqn' => 'Httpful\\Http',
                            'line_start' => 10,
                            'line_end' => 50,
                            'methods' => [
                                [
                                    'name' => 'post',
                                    'line_start' => 20,
                                    'line_end' => 30,
                                ],
                                [
                                    'name' => 'get',
                                    'line_start' => 32,
                                    'line_end' => 45,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $prompt = (new RecallPromptBuilder())->buildSystemMd(
            new TaskBrief(id: 'HTTPFUL-1', description: 'Support QUERY.', files: ['src/Http.php']),
            '',
            new RecallResult([], [], []),
            facts: $facts,
        );

        self::assertStringContainsString("## Navigation Facts\n- `src/Http.php`", $prompt);
        self::assertStringContainsString('  - class `Httpful\\Http` (lines 10-50)', $prompt);
        self::assertStringContainsString('    - `post()` (lines 20-30)', $prompt);
        self::assertStringContainsString('    - `get()` (lines 32-45)', $prompt);
        self::assertStringNotContainsString('- /repo/.agent-loop/map/php-symbols.json', $prompt);
    }
}
