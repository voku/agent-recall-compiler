<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentRecallCompiler\Cli;
use voku\AgentRecallCompiler\PathResolver;
use voku\AgentRecallCompiler\RecallRootResolver;

final class DefaultPathTest extends TestCase
{
    public function testCompactLearningRootWinsDiscovery(): void
    {
        $project = $this->tempDir();
        mkdir($project . '/.agent-loop/learning/findings', 0o775, true);
        mkdir($project . '/infra/doc/agent-learning/findings', 0o775, true);
        $previous = getcwd();

        try {
            chdir($project);

            $root = (new PathResolver())->resolve();
            self::assertSame($project . '/.agent-loop/learning', $root);
            self::assertSame($project, (new RecallRootResolver())->resolve($root)->projectRoot);
        } finally {
            if (is_string($previous)) {
                chdir($previous);
            }
            $this->remove($project);
        }
    }

    public function testHelpDocumentsCompactDefaults(): void
    {
        ob_start();
        try {
            $exit = (new Cli())->run(['agent-recall-compiler', 'help']);
            $output = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }

        self::assertSame(0, $exit);
        self::assertStringContainsString('<cwd>/.agent-loop/learning', $output);
        self::assertStringContainsString('<cwd>/.agent-loop/recall/<task-id>', $output);
    }

    private function tempDir(): string
    {
        $path = sys_get_temp_dir() . '/agent-recall-default-' . bin2hex(random_bytes(6));
        mkdir($path, 0o775, true);

        return str_replace('\\', '/', $path);
    }

    private function remove(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
