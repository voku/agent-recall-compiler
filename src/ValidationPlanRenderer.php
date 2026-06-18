<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler;

final readonly class ValidationPlanRenderer
{
    public function __construct(private RecallPromptBuilder $builder = new RecallPromptBuilder())
    {
    }

    public function render(TaskBrief $task, SelectionResult|RecallResult $result): string
    {
        return $this->builder->buildValidationPlan($task, $result instanceof SelectionResult ? $result->toRecallResult() : $result);
    }
}
