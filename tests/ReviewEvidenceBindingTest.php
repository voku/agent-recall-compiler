<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentRecallCompiler\Review\ReviewReport;

final class ReviewEvidenceBindingTest extends TestCase
{
    public function testReviewReportCarriesTheImplementationSnapshotItReviewed(): void
    {
        $snapshot = 'sha256:' . str_repeat('a', 64);
        $report = new ReviewReport(
            'LOOP-132',
            [],
            contractRevision: 3,
            implementationSnapshot: $snapshot,
        );

        self::assertSame(3, $report->contractRevision);
        self::assertSame($snapshot, $report->implementationSnapshot);
        self::assertSame(3, $report->toArray()['contract_revision']);
        self::assertSame($snapshot, $report->toArray()['implementation_snapshot']);
    }

    public function testReviewReportRejectsPartialEvidenceBinding(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('requires Contract revision and implementation snapshot together');

        new ReviewReport('LOOP-132', [], contractRevision: 3);
    }

    public function testReviewReportRejectsInvalidImplementationSnapshot(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must be a sha256:<64 lowercase hex> digest');

        new ReviewReport(
            'LOOP-132',
            [],
            contractRevision: 3,
            implementationSnapshot: 'not-a-digest',
        );
    }
}
