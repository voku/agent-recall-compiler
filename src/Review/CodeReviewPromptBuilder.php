<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Review;

final class CodeReviewPromptBuilder
{
    public function __construct(private readonly string $workspacePath) {}

    public function build(string $taskId, string $outputDir = '.agent-recall/current'): string
    {
        $prompt = (new ReviewPromptBuilder($this->workspacePath))->buildCodeReviewPrompt($taskId, $outputDir);
        $parts = explode("\n", $prompt, 2);
        $heading = $parts[0];
        $body = $parts[1] ?? '';

        return $heading
            . "\n\n## First-draft falsification lens\n\n"
            . trim((new FirstDraftReviewPromptBuilder())->build())
            . "\n\n"
            . ltrim($body);
    }
}
