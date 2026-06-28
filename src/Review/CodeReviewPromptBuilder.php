<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Review;

final class CodeReviewPromptBuilder
{
    public function __construct(private readonly string $workspacePath) {}

    public function build(string $taskId, string $outputDir = '.agent-recall/current'): string
    {
        return (new ReviewPromptBuilder($this->workspacePath))->buildCodeReviewPrompt($taskId, $outputDir);
    }
}
