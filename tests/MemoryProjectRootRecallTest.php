<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentRecallCompiler\Provider\MemoryRecallProvider;
use voku\AgentRecallCompiler\RecallRootConfig;
use voku\AgentRecallCompiler\RecallRootResolver;
use voku\AgentRecallCompiler\TaskBrief;

final class MemoryProjectRootRecallTest extends TestCase
{
    private string $repositoryRoot;

    protected function setUp(): void
    {
        $this->repositoryRoot = sys_get_temp_dir() . '/recall-project-memory-' . bin2hex(random_bytes(8));
        mkdir($this->repositoryRoot . '/infra/doc/agent-learning', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->repositoryRoot);
    }

    public function testConfiguredProjectRootLoadsRepositoryMemory(): void
    {
        $learningRoot = $this->repositoryRoot . '/infra/doc/agent-learning';
        file_put_contents($learningRoot . '/config.json', json_encode([
            'schema_version' => '1.0',
            'project_root' => '../../..',
        ], JSON_THROW_ON_ERROR));
        file_put_contents($this->repositoryRoot . '/MEMORY.md', "# Repository memory\n\nDurable project rule.\n");

        $config = (new RecallRootResolver())->resolve($learningRoot);
        self::assertSame(str_replace('\\', '/', $this->repositoryRoot), $config->projectRoot);

        $result = (new MemoryRecallProvider())->collect($this->task(), $config);
        self::assertCount(1, $result->facts);
        self::assertSame('memory.global', $result->facts[0]->id);
        self::assertSame('MEMORY.md', $result->facts[0]->sourceRef);
        self::assertSame('# Repository memory' . "\n\n" . 'Durable project rule.', $result->facts[0]->payload['content']);
    }

    public function testProjectMemoryChangesProviderDigest(): void
    {
        $learningRoot = $this->repositoryRoot . '/infra/doc/agent-learning';
        file_put_contents($learningRoot . '/config.json', json_encode(['project_root' => '../../..'], JSON_THROW_ON_ERROR));
        $memory = $this->repositoryRoot . '/MEMORY.md';
        file_put_contents($memory, 'First rule.');

        $config = (new RecallRootResolver())->resolve($learningRoot);
        $first = (new MemoryRecallProvider())->collect($this->task(), $config);

        file_put_contents($memory, 'Second rule.');
        $second = (new MemoryRecallProvider())->collect($this->task(), $config);

        self::assertNotSame($first->sourceDigest, $second->sourceDigest);
        self::assertSame('Second rule.', $second->facts[0]->payload['content']);
    }

    public function testMissingProjectMemoryIsOptionalAndDoesNotFallOutsideProjectRoot(): void
    {
        $projectRoot = $this->repositoryRoot . '/project';
        $learningRoot = $projectRoot . '/infra/doc/agent-learning';
        mkdir($learningRoot, 0777, true);
        file_put_contents($learningRoot . '/config.json', json_encode(['project_root' => '../../..'], JSON_THROW_ON_ERROR));
        file_put_contents($this->repositoryRoot . '/MEMORY.md', 'Must not be selected.');

        $config = (new RecallRootResolver())->resolve($learningRoot);
        $result = (new MemoryRecallProvider())->collect($this->task(), $config);

        self::assertSame([], $result->facts);
    }

    public function testConfiguredProjectRootCannotEscapeKnownRepositoryBoundary(): void
    {
        $projectRoot = $this->repositoryRoot . '/project';
        $learningRoot = $projectRoot . '/infra/doc/agent-learning';
        $outsideRoot = $this->repositoryRoot . '/outside';
        mkdir($learningRoot, 0777, true);
        mkdir($outsideRoot, 0777, true);
        file_put_contents($outsideRoot . '/MEMORY.md', 'External memory must not be ingested.');
        file_put_contents($learningRoot . '/config.json', json_encode([
            'project_root' => '../../../../outside',
        ], JSON_THROW_ON_ERROR));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('configured project_root escapes the inferred repository root');
        (new RecallRootResolver())->resolve($learningRoot);
    }

    public function testKnownLearningRootSuffixResolvesProjectRootWithoutConfig(): void
    {
        $learningRoot = $this->repositoryRoot . '/infra/doc/agent-learning';
        file_put_contents($this->repositoryRoot . '/MEMORY.md', 'Convention-based project memory.');

        $config = (new RecallRootResolver())->resolve($learningRoot);
        self::assertSame(str_replace('\\', '/', $this->repositoryRoot), $config->projectRoot);

        $result = (new MemoryRecallProvider())->collect($this->task(), $config);
        self::assertSame('Convention-based project memory.', $result->facts[0]->payload['content']);
    }

    public function testInvalidConfiguredProjectRootFailsVisibly(): void
    {
        $learningRoot = $this->repositoryRoot . '/infra/doc/agent-learning';
        file_put_contents($learningRoot . '/config.json', json_encode([
            'project_root' => '../../../does-not-exist',
        ], JSON_THROW_ON_ERROR));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('configured project_root directory does not exist');
        (new RecallRootResolver())->resolve($learningRoot);
    }

    public function testDirectLegacyConfigStillUsesLegacyMemoryLookup(): void
    {
        $root = $this->repositoryRoot . '/standalone';
        mkdir($root, 0777, true);
        file_put_contents($root . '/MEMORY.md', 'Standalone memory.');

        $config = new RecallRootConfig($root, 'constraints/active');
        $result = (new MemoryRecallProvider())->collect($this->task(), $config);

        self::assertSame('Standalone memory.', $result->facts[0]->payload['content']);
    }

    private function task(): TaskBrief
    {
        return new TaskBrief('TASK-1', 'Use project memory.', []);
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $entry) {
            $path = $directory . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($directory);
    }
}
