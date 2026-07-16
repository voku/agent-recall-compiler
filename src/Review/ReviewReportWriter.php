<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Review;

use RuntimeException;

final class ReviewReportWriter
{
    public function __construct(private readonly string $workspacePath) {}

    public function write(ReviewReport $report, string $outputDir): void
    {
        if (!BlindSpotReviewer::isValidTaskId($report->taskId)) {
            throw new RuntimeException('Invalid task id.');
        }
        $directory = $this->resolveReviewsDirectory($outputDir);
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create review directory: ' . $directory);
        }
        $base = $directory . '/' . $report->taskId . '.blindspots';
        $json = json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Unable to encode review report JSON: ' . json_last_error_msg());
        }
        if (file_put_contents($base . '.json', $json . "\n") === false) {
            throw new RuntimeException('Unable to write review JSON report.');
        }
        if (file_put_contents($base . '.md', $this->toMarkdown($report)) === false) {
            throw new RuntimeException('Unable to write review Markdown report.');
        }
        $prompt = (new BlindSpotPromptBuilder($this->workspacePath))->build($report, $outputDir);
        if (file_put_contents($base . '.prompt.md', $prompt) === false) {
            throw new RuntimeException('Unable to write review prompt.');
        }
    }

    /**
     * Writes the review report as a `reviews/` subfolder of the same
     * `$outputDir` the review read its compiled recall inputs from, so a
     * project that configures a custom recall root gets one consistent
     * output tree for compile+review instead of a workspace-root-relative
     * `.agent-recall/reviews/` that ignores that configuration entirely.
     */
    private function resolveReviewsDirectory(string $outputDir): string
    {
        $outputDir = rtrim($outputDir, '/');
        if ($outputDir === '' || str_contains($outputDir, '..')) {
            throw new RuntimeException('Invalid review output directory.');
        }

        $base = str_starts_with($outputDir, '/') ? $outputDir : rtrim($this->workspacePath, '/') . '/' . $outputDir;

        return $base . '/reviews';
    }

    private function toMarkdown(ReviewReport $report): string
    {
        $lines = ['# Blind-spot review for ' . $report->taskId, '', 'Status: ' . $report->status(), '', '## Findings', ''];
        if ($report->findings === []) {
            $lines[] = '- [OK] no_findings: No deterministic blind spots were found.';
        }
        foreach ($report->findings as $finding) {
            $lines[] = '- [' . $finding->severity->value . '] ' . $finding->id . ': ' . $finding->message;
            foreach ($finding->evidence as $evidence) {
                $lines[] = '  - ' . $evidence;
            }
        }
        return implode("\n", $lines) . "\n";
    }
}
