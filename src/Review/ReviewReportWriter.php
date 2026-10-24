<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Review;

use RuntimeException;

final class ReviewReportWriter
{
    private readonly ReviewReportPaths $paths;

    public function __construct(private readonly string $workspacePath)
    {
        $this->paths = new ReviewReportPaths($workspacePath);
    }

    public function write(ReviewReport $report, string $outputDir, ?ReviewPromptOptions $promptOptions = null): void
    {
        $directory = $this->paths->reviewsDirectory($outputDir);
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create review directory: ' . $directory);
        }

        $json = json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Unable to encode review report JSON: ' . json_last_error_msg());
        }
        ReviewFileWriter::write($this->workspacePath, $this->paths->json($report->taskId, $outputDir), $json . "\n");
        ReviewFileWriter::write($this->workspacePath, $this->paths->markdown($report->taskId, $outputDir), $this->toMarkdown($report));
        $prompt = (new BlindSpotPromptBuilder($this->workspacePath))->build($report, $outputDir, $promptOptions);
        ReviewFileWriter::write($this->workspacePath, $this->paths->prompt($report->taskId, $outputDir), $prompt);
    }

    private function toMarkdown(ReviewReport $report): string
    {
        $lines = [
            '# Deterministic blind-spot evidence audit for ' . $report->taskId,
            '',
            'Status: ' . $report->status(),
            '',
            '> This report covers deterministic prerequisite/evidence checks only. It does not mean the semantic L2 blind-spot review has been executed.',
            '',
            '## Audit findings',
            '',
        ];
        if ($report->findings === []) {
            $lines[] = '- [OK] no_findings: No deterministic audit findings were found; semantic blind-spot review may still identify issues.';
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
