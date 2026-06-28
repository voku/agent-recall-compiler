<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Review;

final class BlindSpotPromptBuilder
{
    public function __construct(private readonly string $workspacePath) {}

    public function build(ReviewReport $report, string $outputDir): string
    {
        return (new ReviewPromptBuilder($this->workspacePath))->buildBlindSpotPrompt($report, $outputDir);
    }
}
