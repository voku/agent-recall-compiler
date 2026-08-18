<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Review;

use RuntimeException;

/**
 * Canonical artifact paths for one blind-spot review output tree.
 */
final readonly class ReviewReportPaths
{
    public function __construct(private string $workspacePath)
    {
    }

    public function json(string $taskId, string $outputDir): string
    {
        return $this->base($taskId, $outputDir) . '.json';
    }

    public function markdown(string $taskId, string $outputDir): string
    {
        return $this->base($taskId, $outputDir) . '.md';
    }

    public function prompt(string $taskId, string $outputDir): string
    {
        return $this->base($taskId, $outputDir) . '.prompt.md';
    }

    public function reviewsDirectory(string $outputDir): string
    {
        $outputDir = rtrim($outputDir, '/');
        if ($outputDir === '' || str_contains($outputDir, '..')) {
            throw new RuntimeException('Invalid review output directory.');
        }

        $base = str_starts_with($outputDir, '/')
            ? $outputDir
            : rtrim($this->workspacePath, '/') . '/' . $outputDir;

        return $base . '/reviews';
    }

    private function base(string $taskId, string $outputDir): string
    {
        if (!BlindSpotReviewer::isValidTaskId($taskId)) {
            throw new RuntimeException('Invalid task id.');
        }

        return $this->reviewsDirectory($outputDir) . '/' . $taskId . '.blindspots';
    }
}
