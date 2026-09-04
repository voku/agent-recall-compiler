<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentRecallCompiler\Cli;

final class TestDrivenDevelopmentPromptTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-recall-tdd-prompt-' . bin2hex(random_bytes(6));
        foreach ([
            '/proposals/approved',
            '/proposals/applied',
            '/proposals/rejected',
            '/constraints/active',
            '/history',
        ] as $directory) {
            self::assertTrue(mkdir($this->root . $directory, 0o775, true));
        }
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->root)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $path = $item->getPathname();
            $item->isDir() ? rmdir($path) : unlink($path);
        }
        rmdir($this->root);
    }

    public function testBundledTddRecipeCompilesExplicitRedGreenRefactorSemantics(): void
    {
        $manifest = dirname(__DIR__) . '/resources/skills/agent-recall-consumer/operating-prompts.json';
        $output = $this->root . '/output';
        $request = json_encode([
            'id' => 'test-driven-development',
            'arguments' => [],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        self::assertSame(0, (new Cli())->run([
            'agent-recall-compiler',
            'compile',
            '--root',
            $this->root,
            '--task',
            'TDD-1',
            '--description',
            'Change observable behavior using a test-first implementation loop.',
            '--file',
            'src/Example.php',
            '--file',
            'tests/ExampleTest.php',
            '--operating-prompt-manifest',
            $manifest,
            '--operating-prompt',
            $request,
            '--output-dir',
            $output,
            '--compilation-id',
            'compilation.TDD-1.fixed',
        ]));

        $system = (string) file_get_contents($output . '/system.md');
        self::assertStringContainsString('### test-driven-development (L2)', $system);
        self::assertStringContainsString('Test-Driven Development (TDD)', $system);
        self::assertStringContainsString('RED', $system);
        self::assertStringContainsString('GREEN', $system);
        self::assertStringContainsString('REFACTOR', $system);
        self::assertStringContainsString('fail for the intended reason', $system);
        self::assertStringContainsString('smallest production change', $system);
        self::assertStringContainsString('only after GREEN', $system);
        self::assertStringContainsString('Do not manufacture a failing test', $system);
        self::assertStringContainsString('NOT_APPLICABLE', $system);
    }
}
