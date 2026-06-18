<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler;

final readonly class MetaJsonRenderer
{
    public function __construct(private RecallPromptBuilder $builder = new RecallPromptBuilder())
    {
    }

    /** @param array<string, string> $outputHashes */
    public function render(TaskBrief $task, SelectionResult|RecallResult $result, ?string $compilationId = null, array $outputHashes = []): string
    {
        return $this->builder->buildMetaJson($task, $result instanceof SelectionResult ? $result->toRecallResult() : $result, $compilationId, $outputHashes);
    }
}
