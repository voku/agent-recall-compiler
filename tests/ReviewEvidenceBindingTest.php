<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;
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
}
