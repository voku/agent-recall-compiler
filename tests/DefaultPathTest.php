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
    public function testDiscoversCompactLearningRoot(): void
    {
        $project = $this->tempDir();
        mkdir($project . '/.agent-loop/learning/findings', 0o775, true);
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

    public function testHistoricalLearningRootIsNotAutoDiscovered(): void
    {
        $project = $this->tempDir();
        mkdir($project . '/infra/doc/agent-learning/findings', 0o775, true);
        $previous = getcwd();

        try {
            chdir($project);

            self::assertSame($project . '/.agent-loop/learning', (new PathResolver())->resolve());
        } finally {
            if (is_string($previous)) {
                chdir($previous);
            }
            $this->remove($project);
        }
    }

    public function testExplicitHistoricalLearningRootRemainsExplicit(): void
    {
        $project = $this->tempDir();
        $root = $project . '/infra/doc/agent-learning';
        mkdir($root . '/findings', 0o775, true);

        try {
            $config = (new RecallRootResolver())->resolve($root);

            self::assertSame($root, $config->root);
            self::assertSame($root, $config->projectRoot);
        } finally {
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
