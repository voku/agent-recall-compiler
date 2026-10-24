<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentRecallCompiler\Provider\MemoryRecallProvider;
use voku\AgentRecallCompiler\Provider\ScopedDocumentRecallProvider;
use voku\AgentRecallCompiler\RecallRepository;
use voku\AgentRecallCompiler\RecallRootConfig;
use voku\AgentRecallCompiler\Review\ReviewFileWriter;
use voku\AgentRecallCompiler\Review\ReviewPromptBuilder;
use voku\AgentRecallCompiler\TaskBrief;

final class SecurityAuditContainmentTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/recall-security-containment-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testMemoryProviderIgnoresSymlinkedProjectMemory(): void
    {
        $project = $this->root . '/project';
        $outside = $this->root . '/private.md';
        mkdir($project);
        file_put_contents($outside, 'PRIVATE_MEMORY_CONTENT');
        if (!@symlink($outside, $project . '/MEMORY.md')) {
            self::markTestSkipped('symlink() is not available.');
        }

        $config = new RecallRootConfig($project, 'constraints/active', $project);
        $result = (new MemoryRecallProvider())->collect(
            new TaskBrief('TASK-1', 'Use project memory.', []),
            $config,
        );

        self::assertSame([], $result->facts);
    }

    public function testLegacyMemoryLookupIgnoresSymlinkedParentMemory(): void
    {
        $learningRoot = $this->root . '/project/infra/doc/agent-learning';
        mkdir($learningRoot, 0775, true);
        $outside = $this->root . '/private-legacy-memory.md';
        file_put_contents($outside, 'PRIVATE_LEGACY_MEMORY_CONTENT');
        if (!@symlink($outside, dirname($learningRoot) . '/MEMORY.md')) {
            self::markTestSkipped('symlink() is not available.');
        }

        self::assertSame('', (new RecallRepository())->loadMemory($learningRoot));
    }

    public function testScopedDocumentProviderRejectsTraversalOutsideProjectRoot(): void
    {
        $project = $this->root . '/project';
        mkdir($project . '/docs', 0775, true);
        $outside = $this->root . '/private-doc.md';
        file_put_contents($outside, 'PRIVATE_DOCUMENT_CONTENT');
        $manifest = $project . '/docs/manifest.json';
        file_put_contents($manifest, json_encode([
            'schema_version' => '1.0',
            'documents' => [[
                'id' => 'private',
                'type' => 'skill',
                'source' => '../../private-doc.md',
                'scope' => ['src'],
            ]],
        ], JSON_THROW_ON_ERROR));

        try {
            (new ScopedDocumentRecallProvider($manifest))->collect(
                new TaskBrief('TASK-1', 'Inspect source.', ['src/Foo.php']),
                new RecallRootConfig($project, 'constraints/active', $project),
            );
            self::fail('A manifest source outside the project root must be rejected.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('must stay inside the project root', $exception->getMessage());
        }
    }

    public function testReviewPromptDoesNotReadTaskFilesThroughSymlinks(): void
    {
        $outside = $this->root . '/private-source.php';
        file_put_contents($outside, 'PRIVATE_SOURCE_CONTENT');
        mkdir($this->root . '/.agent-recall/current', 0775, true);
        mkdir($this->root . '/src', 0775, true);
        file_put_contents($this->root . '/.agent-recall/current/meta.json', json_encode([
            'task_id' => 'TASK-1',
            'task_files' => ['src/linked.php'],
        ], JSON_THROW_ON_ERROR));
        if (!@symlink($outside, $this->root . '/src/linked.php')) {
            self::markTestSkipped('symlink() is not available.');
        }

        $prompt = (new ReviewPromptBuilder($this->root))->buildCodeReviewPrompt('TASK-1', '.agent-recall/current');

        self::assertStringNotContainsString('PRIVATE_SOURCE_CONTENT', $prompt);
    }

    public function testReviewFileWriterRefusesSymlinkAndOutOfWorkspaceTargets(): void
    {
        $directory = $this->root . '/.agent-recall/current/reviews';
        mkdir($directory, 0775, true);
        $outsideFile = $this->root . '/outside-review.md';
        file_put_contents($outsideFile, 'original review');
        $link = $directory . '/report.md';
        if (!@symlink($outsideFile, $link)) {
            self::markTestSkipped('symlink() is not available.');
        }

        try {
            ReviewFileWriter::write($this->root, $link, 'replacement review');
            self::fail('A symlinked review destination must be refused.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('symlink', $exception->getMessage());
        }
        self::assertSame('original review', file_get_contents($outsideFile));

        $outsideDirectory = sys_get_temp_dir() . '/recall-review-outside-' . bin2hex(random_bytes(8));
        mkdir($outsideDirectory);
        $outsideTarget = $outsideDirectory . '/report.md';
        try {
            ReviewFileWriter::write($this->root, $outsideTarget, 'outside write');
            self::fail('A review destination outside the workspace must be refused.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('escapes the workspace', $exception->getMessage());
        } finally {
            self::assertFileDoesNotExist($outsideTarget);
            rmdir($outsideDirectory);
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (is_link($directory)) {
            unlink($directory);
            return;
        }
        if (!is_dir($directory)) {
            return;
        }
        foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $entry) {
            $path = $directory . '/' . $entry;
            if (is_link($path) || is_file($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                $this->removeDirectory($path);
            }
        }
        rmdir($directory);
    }
}
