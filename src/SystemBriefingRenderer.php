<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler;

final readonly class SystemBriefingRenderer
{
    public function __construct(private RecallPromptBuilder $builder = new RecallPromptBuilder())
    {
    }

    public function render(TaskBrief $task, string $memory, SelectionResult|RecallResult $result): string
    {
        return $this->builder->buildSystemMd($task, $memory, $result instanceof SelectionResult ? $result->toRecallResult() : $result);
    }
}
