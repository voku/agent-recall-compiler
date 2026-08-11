<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
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
