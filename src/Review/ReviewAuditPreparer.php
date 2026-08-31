<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Review;

use InvalidArgumentException;
use RuntimeException;

/**
 * Public owner entrypoint for preparing one deterministic blind-spot evidence audit.
 *
 * The returned artifact identifies the exact persisted JSON report. This API does
 * not acknowledge review, decide lifecycle currency, or execute the emitted L2
 * semantic review prompt.
 */
final readonly class ReviewAuditPreparer
{
    public function __construct(private string $workspacePath)
    {
        if (trim($this->workspacePath) === '') {
            throw new InvalidArgumentException('Review audit workspace path must be non-empty.');
        }
    }

    public function prepare(
        string $taskId,
        string $outputDirectory,
        ?int $contractRevision = null,
        ?string $implementationSnapshot = null,
    ): ReviewReportArtifact {
        if (!BlindSpotReviewer::isValidTaskId($taskId)) {
            throw new InvalidArgumentException('Review audit task id is invalid.');
        }
        if (trim($outputDirectory) === '') {
            throw new InvalidArgumentException('Review audit output directory must be non-empty.');
        }
        if (($contractRevision === null) !== ($implementationSnapshot === null)) {
            throw new InvalidArgumentException(
                'Review audit binding requires Contract revision and implementation snapshot together.',
            );
        }

        $report = (new BlindSpotReviewer($this->workspacePath))->review($taskId, $outputDirectory);
        if ($contractRevision !== null) {
            $report = new ReviewReport(
                taskId: $report->taskId,
                findings: $report->findings,
                contractRevision: $contractRevision,
                implementationSnapshot: $implementationSnapshot,
            );
        }

        (new ReviewReportWriter($this->workspacePath))->write($report, $outputDirectory);

        $artifact = (new ReviewReportReader($this->workspacePath))->read($taskId, $outputDirectory);
        if (!$artifact instanceof ReviewReportArtifact) {
            throw new RuntimeException('Review audit preparation completed without a readable persisted report.');
        }

        return $artifact;
    }
}
