<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentRecallCompiler\Review\ReviewAuditPreparer;

final class ReviewAuditPreparerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-recall-review-audit-api-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o775, true);
    }

    protected function tearDown(): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    public function testPrepareReturnsExactPersistedBoundAuditArtifact(): void
    {
        $snapshot = 'sha256:' . str_repeat('a', 64);

        $artifact = (new ReviewAuditPreparer($this->root))->prepare(
            taskId: 'ABC-123',
            outputDirectory: '.agent-recall/current',
            contractRevision: 7,
            implementationSnapshot: $snapshot,
        );

        self::assertSame('ABC-123', $artifact->report->taskId);
        self::assertSame(7, $artifact->report->contractRevision);
        self::assertSame($snapshot, $artifact->report->implementationSnapshot);
        self::assertMatchesRegularExpression('/^sha256:[a-f0-9]{64}$/', $artifact->sha256);
        self::assertFileExists($artifact->jsonPath);
        self::assertFileExists($this->root . '/.agent-recall/current/reviews/ABC-123.blindspots.md');
        self::assertFileExists($this->root . '/.agent-recall/current/reviews/ABC-123.blindspots.prompt.md');

        $bytes = (string) file_get_contents($artifact->jsonPath);
        self::assertSame('sha256:' . hash('sha256', $bytes), $artifact->sha256);
    }

    public function testPrepareRejectsPartialEvidenceBinding(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requires Contract revision and implementation snapshot together');

        (new ReviewAuditPreparer($this->root))->prepare(
            taskId: 'ABC-123',
            outputDirectory: '.agent-recall/current',
            contractRevision: 7,
        );
    }
}
