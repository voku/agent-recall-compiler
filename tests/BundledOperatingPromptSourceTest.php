<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentRecallCompiler\Command\CompileCommand;

final class BundledOperatingPromptSourceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-recall-bundled-source-' . bin2hex(random_bytes(6));
        foreach ([
            '/proposals/approved',
            '/proposals/applied',
            '/proposals/rejected',
            '/constraints/active',
            '/history',
        ] as $directory) {
            $path = $this->root . $directory;
            if (!mkdir($path, 0o777, true) && !is_dir($path)) {
                throw new RuntimeException('Unable to create Recall fixture directory: ' . $path);
            }
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

    public function testBundledSourceCompilesWithoutCallerKnowingManifestPath(): void
    {
        $output = $this->root . '/output';
        $request = json_encode([
            'id' => 'adversarial-review',
            'arguments' => ['minimum_failure_modes' => 3],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        self::assertSame(0, (new CompileCommand(reportToStdout: false))->run([
            '--root',
            $this->root,
            '--task',
            'BUNDLED-SOURCE-1',
            '--description',
            'Review the implementation as a first draft.',
            '--file',
            'src/Example.php',
            '--operating-prompt-source',
            'bundled',
            '--operating-prompt',
            $request,
            '--output-dir',
            $output,
            '--compilation-id',
            'compilation.BUNDLED-SOURCE-1.fixed',
        ]));

        $system = (string) file_get_contents($output . '/system.md');
        self::assertStringContainsString('### adversarial-review (L2)', $system);
        self::assertStringContainsString('CLEAN remains valid', $system);
    }

    public function testUnknownOperatingPromptSourceFailsClosed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('compile --operating-prompt-source must be bundled');

        (new CompileCommand(reportToStdout: false))->run([
            '--root',
            $this->root,
            '--task',
            'BUNDLED-SOURCE-UNKNOWN',
            '--description',
            'Review the implementation as a first draft.',
            '--operating-prompt-source',
            'remote',
            '--output-dir',
            $this->root . '/unknown-source-output',
            '--compilation-id',
            'compilation.BUNDLED-SOURCE-UNKNOWN.fixed',
        ]);
    }

    public function testOperatingPromptWithoutSourceOrManifestStillFailsClosed(): void
    {
        $request = json_encode([
            'id' => 'adversarial-review',
            'arguments' => ['minimum_failure_modes' => 3],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('compile operating prompts require at least one --operating-prompt-manifest');

        (new CompileCommand(reportToStdout: false))->run([
            '--root',
            $this->root,
            '--task',
            'BUNDLED-SOURCE-2',
            '--description',
            'Review the implementation as a first draft.',
            '--file',
            'src/Example.php',
            '--operating-prompt',
            $request,
            '--output-dir',
            $this->root . '/missing-source-output',
            '--compilation-id',
            'compilation.BUNDLED-SOURCE-2.fixed',
        ]);
    }
}
