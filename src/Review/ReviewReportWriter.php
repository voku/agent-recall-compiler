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

    public function write(ReviewReport $report, string $outputDir): void
    {
        $directory = $this->paths->reviewsDirectory($outputDir);
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create review directory: ' . $directory);
        }

        $json = json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Unable to encode review report JSON: ' . json_last_error_msg());
        }
        if (file_put_contents($this->paths->json($report->taskId, $outputDir), $json . "\n") === false) {
            throw new RuntimeException('Unable to write review JSON report.');
        }
        if (file_put_contents($this->paths->markdown($report->taskId, $outputDir), $this->toMarkdown($report)) === false) {
            throw new RuntimeException('Unable to write review Markdown report.');
        }
        $prompt = (new BlindSpotPromptBuilder($this->workspacePath))->build($report, $outputDir);
        if (file_put_contents($this->paths->prompt($report->taskId, $outputDir), $prompt) === false) {
            throw new RuntimeException('Unable to write review prompt.');
        }
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
