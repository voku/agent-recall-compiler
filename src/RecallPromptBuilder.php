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
                $statsLine = $this->formatOutcomeStats($result, $g->id);
                if ($statsLine !== null) {
                    $md[] = "- **Outcome signals**: " . $statsLine;
                }
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

        if ($result->selectedConstraints !== []) {
            $md[] = "## Selected Hard Constraints";
            $md[] = "The learning only counts when it changes or verifies enforceable repository behavior. Do not stop at prose, summaries, or recommendations while a selected hard constraint is in scope.";
            $md[] = "";
            $md[] = "Work until the relevant rule is active in the repository and the required validation command has been run. If implementation is blocked, report the concrete blocker and the rule identifier that could not be validated.";
            $md[] = "";
            foreach ($result->selectedConstraints as $constraint) {
                $md[] = "### Constraint: " . $constraint->id;
                $md[] = "- **Engine**: " . $this->formatEngine($constraint->engine);
                $md[] = "- **Rule identifier**: `" . $constraint->ruleIdentifier . "`";
                $md[] = "- **Scope**: " . implode(', ', $constraint->scope);
                $md[] = "- **Source proposal**: `" . $constraint->sourceProposal . "`";
                $statsLine = $this->formatOutcomeStats($result, $constraint->id);
                if ($statsLine !== null) {
                    $md[] = "- **Outcome signals**: " . $statsLine;
                }
                $md[] = "";
                $md[] = "Required validation:";
                foreach ($constraint->validationCommands as $command) {
                    $md[] = "```bash";
                    $md[] = $command;
                    $md[] = "```";
                }
                $md[] = "";
            }
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

    /**
     * @param array<string, string> $outputHashes
     */
    public function buildMetaJson(TaskBrief $task, RecallResult $result, ?string $compilationId = null, array $outputHashes = []): string
    {
        $data = [
            'schema_version' => '1.0',
            'compilation_id' => $compilationId,
            'task_id' => $task->id,
            'task_files' => $task->files,
            'compiled_at' => (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM),
            'selected_guidance' => array_map(static fn(RecallGuidance $g) => $g->id, $result->selectedGuidance),
            'evaluated_guidance' => array_map(static fn(EvaluatedGuidance $g) => $g->toArray(), $result->evaluatedGuidance),
            'selected_constraints' => array_map(static fn(ConstraintManifest $c) => [
                'id' => $c->id,
                'engine' => $c->engine,
                'rule_identifier' => $c->ruleIdentifier,
                'source_proposal' => $c->sourceProposal,
                'selection_reason' => SelectionReason::CONSTRAINT_SCOPE->value,
            ], $result->selectedConstraints),
            'selected_rejections' => array_map(static fn(RecallRejection $rj) => $rj->id, $result->selectedRejections),
            'outcome_stats' => $result->outcomeStats,
            'warnings' => $result->warnings,
            'output_hashes' => $outputHashes,
        ];

        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public function buildValidationPlan(TaskBrief $task, RecallResult $result): string
    {
        $md = [];
        $md[] = "# Validation Plan";
        $md[] = "";
        $md[] = "## Required Validation";
        $md[] = "";

        $hasValidation = false;
        foreach ($this->constraintsByEngine($result->selectedConstraints) as $engine => $constraints) {
            $hasValidation = true;
            $md[] = "### " . $this->formatEngine($engine);
            $md[] = "";
            foreach ($this->uniqueConstraintCommands($constraints) as $command) {
                $md[] = "```bash";
                $md[] = $command;
                $md[] = "```";
                $md[] = "";
            }
            $md[] = "Required rule identifiers:";
            $md[] = "";
            foreach ($constraints as $constraint) {
                $md[] = "- `" . $constraint->ruleIdentifier . "`";
            }
            $md[] = "";
        }

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

        if ($result->selectedConstraints !== []) {
            $md[] = "## Provenance";
            $md[] = "";
            foreach ($result->selectedConstraints as $constraint) {
                $md[] = "- `" . $constraint->sourceProposal . "`";
            }
            $md[] = "";
        }

        return implode("\n", $md);
    }

    public function buildRecallLogDraft(TaskBrief $task, RecallResult $result, ?string $compilationId = null): string
    {
        $selectedIds = array_map(static fn(RecallGuidance $g) => $g->id, $result->selectedGuidance);
        $selectedConstraintIds = array_map(static fn(ConstraintManifest $c) => $c->id, $result->selectedConstraints);
        $sourceProposalIds = array_map(static fn(ConstraintManifest $c) => $c->sourceProposal, $result->selectedConstraints);
        $selectedById = [];
        foreach ($result->evaluatedGuidance as $evaluatedGuidance) {
            if ($evaluatedGuidance->selected) {
                $selectedById[$evaluatedGuidance->guidanceId] = $evaluatedGuidance;
            }
        }
        if ($selectedById === []) {
            foreach ($result->selectedGuidance as $guidance) {
                $selectedById[$guidance->id] = new EvaluatedGuidance(
                    $guidance->id,
                    GuidanceType::tryFrom((string)$guidance->targetType) ?? GuidanceType::SKILL,
                    true,
                    true,
                    SelectionReason::SCOPE_OVERLAP,
                    null,
                    $task->files,
                );
            }
            foreach ($result->selectedConstraints as $constraint) {
                $selectedById[$constraint->id] = new EvaluatedGuidance(
                    $constraint->id,
                    GuidanceType::CONSTRAINT,
                    true,
                    true,
                    SelectionReason::CONSTRAINT_SCOPE,
                    null,
                    $task->files,
                    $constraint->sourceProposal,
                );
            }
            ksort($selectedById);
        }
        
        $data = [
            'schema_version' => '1.0',
            'id' => 'outcome.' . (new DateTimeImmutable('now'))->format('Y-m-d') . '.000',
            'compilation_id' => $compilationId,
            'task_id' => $task->id,
            'task_files' => $task->files,
            'session' => 'sess_placeholder',
            'created_at' => (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM),
            'guidance_used' => $selectedIds,
            'constraints_used' => $selectedConstraintIds,
            'applied_proposals' => array_values(array_unique([...$selectedIds, ...$sourceProposalIds])),
            'selected' => array_values(array_unique([...$selectedIds, ...$selectedConstraintIds])),
            'evaluated_guidance' => array_map(static fn(EvaluatedGuidance $g) => $g->toArray(), $result->evaluatedGuidance),
            'guidance_outcomes' => array_map(
                static fn(EvaluatedGuidance $g) => [
                    'guidance_id' => $g->guidanceId,
                    'guidance_type' => $g->guidanceType->value,
                    'selected' => true,
                    'applied' => false,
                    'outcome' => OutcomeValue::UNKNOWN->value,
                    'comment' => null,
                ],
                array_values($selectedById),
            ),
            'applied' => [],
            'helpful' => [],
            'irrelevant' => [],
            'harmful' => [],
            'result' => 'successful',
            'comment' => 'Complete guidance_outcomes after the session. Selection alone is not proof of usefulness.',
        ];

        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @param list<ConstraintManifest> $constraints
     * @return array<string, list<ConstraintManifest>>
     */
    private function constraintsByEngine(array $constraints): array
    {
        $byEngine = [];
        foreach ($constraints as $constraint) {
            $byEngine[$constraint->engine][] = $constraint;
        }
        ksort($byEngine);

        return $byEngine;
    }

    private function formatEngine(string $engine): string
    {
        return match ($engine) {
            'phpstan' => 'PHPStan',
            'php_cs_fixer' => 'PHP-CS-Fixer',
            'ci' => 'CI',
            default => ucfirst($engine),
        };
    }

    private function formatOutcomeStats(RecallResult $result, string $id): ?string
    {
        $stats = $result->outcomeStats[$id] ?? null;
        if ($stats === null) {
            return null;
        }
        if (
            $stats['selected_count'] === 0
            &&
            $stats['helpful_count'] === 0
            &&
            $stats['irrelevant_count'] === 0
            &&
            $stats['harmful_count'] === 0
            &&
            $stats['violation_detected_count'] === 0
        ) {
            return null;
        }

        return sprintf(
            'selected=%d, helpful=%d, irrelevant=%d, harmful=%d, violation_detected=%d',
            $stats['selected_count'],
            $stats['helpful_count'],
            $stats['irrelevant_count'],
            $stats['harmful_count'],
            $stats['violation_detected_count'],
        );
    }

    /**
     * @param list<ConstraintManifest> $constraints
     * @return list<string>
     */
    private function uniqueConstraintCommands(array $constraints): array
    {
        $commands = [];
        foreach ($constraints as $constraint) {
            foreach ($constraint->validationCommands as $command) {
                $commands[$command] = true;
            }
        }

        return array_keys($commands);
    }
}
