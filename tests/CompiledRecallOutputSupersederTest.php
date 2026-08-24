<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentRecallCompiler\Output\CompiledRecallOutputSuperseder;

/** @internal */
final class CompiledRecallOutputSupersederTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/recall-superseder-' . bin2hex(random_bytes(6));
        if (!mkdir($this->root, 0o775, true) && !is_dir($this->root)) {
            throw new RuntimeException('Unable to create fixture directory.');
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/*') ?: [] as $path) {
            $this->remove($path);
        }
        rmdir($this->root);
    }

    public function testAbsentOutputNeedsNoArchive(): void
    {
        self::assertNull((new CompiledRecallOutputSuperseder())->archiveIfPresent($this->root . '/TASK-1'));
    }

    public function testOutputIsArchivedByItsIdentityDigest(): void
    {
        $directory = $this->root . '/TASK-1';
        mkdir($directory, 0o775, true);
        $meta = "{\"schema_version\":\"1.0\",\"task_id\":\"TASK-1\"}\n";
        file_put_contents($directory . '/meta.json', $meta);
        file_put_contents($directory . '/system.md', 'old output');

        $archive = (new CompiledRecallOutputSuperseder())->archiveIfPresent($directory);

        self::assertSame($directory . '.superseded-' . substr(hash('sha256', $meta), 0, 12), $archive);
        self::assertDirectoryDoesNotExist($directory);
        self::assertDirectoryExists($archive);
        self::assertSame('old output', file_get_contents($archive . '/system.md'));
    }

    public function testMissingIdentityUsesUnknownSuffixAndDoesNotOverwriteExistingArchive(): void
    {
        $directory = $this->root . '/TASK-1';
        mkdir($directory, 0o775, true);
        file_put_contents($directory . '/system.md', 'current');
        mkdir($directory . '.superseded-unknown', 0o775, true);
        file_put_contents($directory . '.superseded-unknown/existing.txt', 'keep');

        $archive = (new CompiledRecallOutputSuperseder())->archiveIfPresent($directory);

        self::assertSame($directory . '.superseded-unknown-1', $archive);
        self::assertDirectoryExists($archive);
        self::assertSame('keep', file_get_contents($directory . '.superseded-unknown/existing.txt'));
        self::assertSame('current', file_get_contents($archive . '/system.md'));
    }

    private function remove(string $path): void
    {
        if (!is_dir($path)) {
            is_file($path) && unlink($path);
            return;
        }

        foreach (glob($path . '/*') ?: [] as $child) {
            $this->remove($child);
        }
        rmdir($path);
    }
}
