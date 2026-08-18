<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Review;

use InvalidArgumentException;

/**
 * One parsed blind-spot review report bound to the exact persisted JSON bytes.
 */
final readonly class ReviewReportArtifact
{
    public function __construct(
        public ReviewReport $report,
        public string $jsonPath,
        public string $sha256,
    ) {
        if ($this->jsonPath === '') {
            throw new InvalidArgumentException('Review report JSON path must be non-empty.');
        }
        if (preg_match('/^sha256:[a-f0-9]{64}$/', $this->sha256) !== 1) {
            throw new InvalidArgumentException('Review report identity must be a sha256:<64 lowercase hex> digest.');
        }
    }
}
