<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler;

use DateTimeImmutable;
use DateTimeInterface;

final class RecallPromptBuilder
{
    public function buildSystemMd(TaskBrief $task, string $memory, RecallResult $result): string
    {
        $md = [];
        $md[] = "# L2 Meta-Prompt Briefing for Task: " . $task->id;
        $md[] = "> Generated at " . (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM);
        $md[] = "";

        if ($task->description !== '') {
            $md[] = "## Task Description";
            $md[] = trim($task->description);
            $md[] = "";
        }

        if (trim($memory) !== '') {
            $md[] = "## Repository Global Memory (`MEMORY.md`)";
            $md[] = trim($memory);
            $md[] = "";
        }

        if ($result->selectedGuidance !== []) {
            $md[] = "## Selected Active Guidance";
            foreach ($result->selectedGuidance as $g) {
                $md[] = "### Guidance: " . $g->id;
                $md[] = "- **Target**: " . ($g->target ?? 'global');
                $md[] = "- **Scope**: " . implode(', ', $g->scope);
                if ($g->boundary !== null && $g->boundary !== '') {
                    $md[] = "- **Boundary**: " . $g->boundary;
                }
                $md[] = "";
                $md[] = "#### Directive:";
                if ($g->new !== null && trim($g->new) !== '') {
                    $md[] = "```text";
                    $md[] = trim($g->new);
                    $md[] = "```";
                } else {
                    $md[] = "*No new wording provided (Delete/Reject action).*";
                }
                $md[] = "";
            }
        } else {
            $md[] = "## Selected Active Guidance";
            $md[] = "*No active task-specific guidance matched this task's scope.*";
            $md[] = "";
        }

        if ($result->selectedRejections !== []) {
            $md[] = "## Past Rejected Proposals (Warnings)";
            $md[] = "⚠️ **Do not implement the following patterns, as they were previously proposed and rejected:**";
            $md[] = "";
            foreach ($result->selectedRejections as $rj) {
                $md[] = "### Rejection: " . $rj->id;
                $md[] = "- **Action**: " . $rj->action;
                if ($rj->target !== null) {
                    $md[] = "- **Target**: " . $rj->target;
                }
                $md[] = "- **Reason for Rejection**: *" . $rj->reason . "*";
                $md[] = "";
            }
        }

        if ($result->warnings !== []) {
            $md[] = "## Outcome-Driven Warnings";
            foreach ($result->warnings as $warning) {
                $md[] = "⚠️ " . $warning;
            }
            $md[] = "";
        }

        return implode("\n", $md);
    }

    public function buildMetaJson(TaskBrief $task, RecallResult $result): string
    {
        $data = [
            'task_id' => $task->id,
            'compiled_at' => (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM),
            'selected_guidance' => array_map(static fn(RecallGuidance $g) => $g->id, $result->selectedGuidance),
            'selected_rejections' => array_map(static fn(RecallRejection $rj) => $rj->id, $result->selectedRejections),
            'warnings' => $result->warnings,
        ];

        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public function buildValidationPlan(TaskBrief $task, RecallResult $result): string
    {
        $md = [];
        $md[] = "# Validation Plan for Task: " . $task->id;
        $md[] = "Verify the changes using the following validation instructions:";
        $md[] = "";

        $hasValidation = false;
        foreach ($result->selectedGuidance as $g) {
            if ($g->validation !== []) {
                $hasValidation = true;
                $md[] = "## Guidance: " . $g->id;
                foreach ($g->validation as $v) {
                    $md[] = "- " . $v;
                }
                $md[] = "";
            }
        }

        if (!$hasValidation) {
            $md[] = "*No task-specific validation commands were registered for the matching guidance.*";
            $md[] = "";
        }

        return implode("\n", $md);
    }

    public function buildRecallLogDraft(TaskBrief $task, RecallResult $result): string
    {
        $selectedIds = array_map(static fn(RecallGuidance $g) => $g->id, $result->selectedGuidance);
        
        $data = [
            'schema_version' => '1.0',
            'id' => 'outcome.' . (new DateTimeImmutable('now'))->format('Y-m-d') . '.000',
            'task_id' => $task->id,
            'session' => 'sess_placeholder',
            'created_at' => (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM),
            'guidance_used' => $selectedIds,
            'applied_proposals' => $selectedIds,
            'selected' => $selectedIds,
            'applied' => $selectedIds,
            'helpful' => $selectedIds,
            'irrelevant' => [],
            'harmful' => [],
            'result' => 'successful',
            'comment' => 'Guidance was helpful.',
        ];

        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
