<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentRecallCompiler\Review\BlindSpotFinding;
use voku\AgentRecallCompiler\Review\ReviewReport;
use voku\AgentRecallCompiler\Review\ReviewReportReader;
use voku\AgentRecallCompiler\Review\ReviewSeverity;

final class ReviewReportReaderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-recall-review-reader-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testMissingReportReturnsNullWithoutCreatingState(): void
    {
        $reader = new ReviewReportReader($this->root);

        self::assertNull($reader->read('ABC-123', '.agent-recall/current'));
        self::assertDirectoryDoesNotExist($this->root . '/.agent-recall/current/reviews');
    }

    public function testReadsTypedReportBoundToExactPersistedBytes(): void
    {
        $report = new ReviewReport(
            taskId: 'ABC-123',
            findings: [
                new BlindSpotFinding(
                    id: 'scope-risk',
                    severity: ReviewSeverity::WARN,
                    message: 'Scope requires explicit verification.',
                    evidence: ['src/Foo.php:12'],
                ),
            ],
            contractRevision: 3,
            implementationSnapshot: 'sha256:' . str_repeat('a', 64),
        );
        $contents = json_encode(
            $report->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . "\n";
        $path = $this->writeReport('ABC-123', $contents);

        $artifact = (new ReviewReportReader($this->root))->read('ABC-123', '.agent-recall/current');

        self::assertNotNull($artifact);
        self::assertSame($path, $artifact->jsonPath);
        self::assertSame('sha256:' . hash('sha256', $contents), $artifact->sha256);
        self::assertSame('ABC-123', $artifact->report->taskId);
        self::assertSame('warn', $artifact->report->status());
        self::assertSame(3, $artifact->report->contractRevision);
        self::assertSame('sha256:' . str_repeat('a', 64), $artifact->report->implementationSnapshot);
        self::assertCount(1, $artifact->report->findings);
        self::assertSame(ReviewSeverity::WARN, $artifact->report->findings[0]->severity);
        self::assertSame(['src/Foo.php:12'], $artifact->report->findings[0]->evidence);
    }

    public function testRejectsStatusThatDoesNotMatchFindings(): void
    {
        $contents = json_encode([
            'version' => ReviewReport::VERSION,
            'task_id' => 'ABC-123',
            'status' => 'ok',
            'contract_revision' => null,
            'implementation_snapshot' => null,
            'findings' => [[
                'id' => 'real-failure',
                'severity' => 'FAIL',
                'message' => 'This report cannot honestly claim ok.',
                'evidence' => ['tests/FooTest.php'],
            ]],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        $this->writeReport('ABC-123', $contents);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('status does not match its findings');

        (new ReviewReportReader($this->root))->read('ABC-123', '.agent-recall/current');
    }

    public function testRejectsInvalidBindingInsteadOfWeakeningIt(): void
    {
        $contents = json_encode([
            'version' => ReviewReport::VERSION,
            'task_id' => 'ABC-123',
            'status' => 'ok',
            'contract_revision' => 2,
            'implementation_snapshot' => null,
            'findings' => [],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        $this->writeReport('ABC-123', $contents);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Review evidence binding requires Contract revision and implementation snapshot together.');

        (new ReviewReportReader($this->root))->read('ABC-123', '.agent-recall/current');
    }

    public function testRejectsReportForAnotherTask(): void
    {
        $contents = json_encode([
            'version' => ReviewReport::VERSION,
            'task_id' => 'OTHER-999',
            'status' => 'ok',
            'contract_revision' => null,
            'implementation_snapshot' => null,
            'findings' => [],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        $this->writeReport('ABC-123', $contents);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('task_id does not match the requested task');

        (new ReviewReportReader($this->root))->read('ABC-123', '.agent-recall/current');
    }

    private function writeReport(string $taskId, string $contents): string
    {
        $directory = $this->root . '/.agent-recall/current/reviews';
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create review fixture directory.');
        }
        $path = $directory . '/' . $taskId . '.blindspots.json';
        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException('Unable to write review fixture.');
        }

        return $path;
    }

    private function removeDirectory(string $path): void
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
