<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentRecallCompiler\Cli;

final class BundledOperatingPromptCatalogTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-recall-bundled-prompts-' . bin2hex(random_bytes(6));
        foreach ([
            '/proposals/approved',
            '/proposals/applied',
            '/proposals/rejected',
            '/constraints/active',
            '/history',
        ] as $directory) {
            self::assertTrue(mkdir($this->root . $directory, 0777, true));
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

    public function testBundledAdversarialReviewCompilesThroughTheRealCli(): void
    {
        $manifest = dirname(__DIR__) . '/skills/agent-recall-consumer/operating-prompts.json';
        self::assertFileExists($manifest);

        $output = $this->root . '/output';
        $request = json_encode([
            'id' => 'adversarial-review',
            'arguments' => ['minimum_failure_modes' => 3],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        self::assertSame(0, (new Cli())->run([
            'agent-recall-compiler',
            'compile',
            '--root',
            $this->root,
            '--task',
            'BUNDLED-PROMPT-1',
            '--description',
            'Review the current implementation as a first draft.',
            '--file',
            'src/Example.php',
            '--operating-prompt-manifest',
            $manifest,
            '--operating-prompt',
            $request,
            '--output-dir',
            $output,
            '--compilation-id',
            'compilation.BUNDLED-PROMPT-1.fixed',
        ]));

        $system = (string) file_get_contents($output . '/system.md');
        self::assertStringContainsString('### adversarial-review (L2)', $system);
        self::assertStringContainsString('distinct plausible failure-mode hypotheses or attack scenarios', $system);
        self::assertStringContainsString('Do not manufacture defects merely to satisfy the numeric floor', $system);
        self::assertStringContainsString('CLEAN remains valid', $system);
    }
}
