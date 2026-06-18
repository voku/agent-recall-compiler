<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler;

final readonly class OutcomeDraftRenderer
{
    public function __construct(private RecallPromptBuilder $builder = new RecallPromptBuilder())
    {
    }

    public function render(TaskBrief $task, SelectionResult|RecallResult $result, ?string $compilationId = null): string
    {
        return $this->builder->buildRecallLogDraft($task, $result instanceof SelectionResult ? $result->toRecallResult() : $result, $compilationId);
    }
}
