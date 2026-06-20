<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler;

final class RecallDecisionEngine
{
    /**
     * Conflicts and unresolved-state issues are raised as
     * {@see RecallCompilationBlockedException} so the CLI can fail closed with a
     * clear BLOCKED surface instead of emitting a degraded briefing.
     *
     * @param list<RecallGuidance> $activeGuidance
     * @param list<RecallRejection> $rejectedGuidance
     * @param list<array<string, mixed>> $outcomes
     * @param list<ConstraintManifest> $constraints
     * @return RecallResult
     */
    public function decide(
        TaskBrief $task,
        array $activeGuidance,
        array $rejectedGuidance,
        array $outcomes,
        array $constraints = [],
    ): RecallResult {
        $selectedGuidance = [];
        $selectedRejections = [];
        $selectedConstraints = [];
        $warnings = [];
        $evaluatedGuidance = [];

        // 1. Select active guidance matching task files
        foreach ($activeGuidance as $g) {
            $matchingFiles = $this->matchingTaskFiles($g->scope, $task->files);
            $matches = $matchingFiles !== [];
            $evaluatedGuidance[] = new EvaluatedGuidance(
                $g->id,
                $this->guidanceType($g->targetType, $g->id),
                $matches,
                $matches,
                $matches ? $this->selectionReason($g->scope) : null,
                $matches ? null : ExclusionReason::NO_SCOPE_OVERLAP,
                $matchingFiles,
            );
            if ($matches) {
                $selectedGuidance[] = $g;
            }
        }

        // 2. Select matching rejections to warn about past mistakes
        foreach ($rejectedGuidance as $rg) {
            if ($this->matchesAnyScope($rg->scope, $task->files)) {
                $selectedRejections[] = $rg;
            }
        }

        // 2b. Select constraints by scope, not semantic similarity.
        foreach ($constraints as $constraint) {
            $matchingFiles = $this->matchingTaskFiles($constraint->scope, $task->files);
            if ($matchingFiles === []) {
                $evaluatedGuidance[] = new EvaluatedGuidance(
                    $constraint->id,
                    GuidanceType::CONSTRAINT,
                    false,
                    false,
                    null,
                    ExclusionReason::NO_SCOPE_OVERLAP,
                    [],
                    $constraint->sourceProposal,
                );
                continue;
            }
            if ($constraint->status === 'superseded') {
                throw new RecallCompilationBlockedException(sprintf("Compilation blocked: selected constraint '%s' is superseded.", $constraint->id));
            }
            if ($constraint->status !== 'active') {
                $evaluatedGuidance[] = new EvaluatedGuidance(
                    $constraint->id,
                    GuidanceType::CONSTRAINT,
                    false,
                    false,
                    null,
                    ExclusionReason::INACTIVE,
                    $matchingFiles,
                    $constraint->sourceProposal,
                );
                continue;
            }
            if ($constraint->validationCommands === []) {
                throw new RecallCompilationBlockedException(sprintf("Compilation blocked: selected active constraint '%s' has no required validation command.", $constraint->id));
            }
            if ($constraint->ruleIdentifier === '') {
                throw new RecallCompilationBlockedException(sprintf("Compilation blocked: selected active constraint '%s' has no rule identifier.", $constraint->id));
            }
            $evaluatedGuidance[] = new EvaluatedGuidance(
                $constraint->id,
                GuidanceType::CONSTRAINT,
                true,
                true,
                SelectionReason::CONSTRAINT_SCOPE,
                null,
                $matchingFiles,
                $constraint->sourceProposal,
            );
            $selectedConstraints[] = $constraint;
        }

        // 3. Process outcomes for selected guidance
        $selectedGuidanceIds = array_map(static fn(RecallGuidance $g) => $g->id, $selectedGuidance);
        $selectedConstraintIds = array_map(static fn(ConstraintManifest $constraint) => $constraint->id, $selectedConstraints);
        $selectedConstraintSourceProposals = array_map(static fn(ConstraintManifest $constraint) => $constraint->sourceProposal, $selectedConstraints);
        $selectedOutcomeIds = array_values(array_unique([...$selectedGuidanceIds, ...$selectedConstraintIds, ...$selectedConstraintSourceProposals]));
        $outcomeStats = $this->buildOutcomeStats($selectedOutcomeIds, $outcomes);
        foreach ($outcomes as $outcome) {
            if (isset($outcome['guidance_id'], $outcome['outcome']) && is_string($outcome['guidance_id']) && is_string($outcome['outcome'])) {
                if (in_array($outcome['guidance_id'], $selectedOutcomeIds, true)) {
                    if ($outcome['outcome'] === OutcomeValue::HARMFUL->value) {
                        $warnings[] = sprintf(
                            "Guidance '%s' was previously marked as HARMFUL in task '%s'. Reason: %s",
                            $outcome['guidance_id'],
                            $outcome['task_id'] ?? 'unknown',
                            $outcome['comment'] ?? 'None provided',
                        );
                    }
                    if ($outcome['outcome'] === OutcomeValue::IRRELEVANT->value) {
                        $warnings[] = sprintf(
                            "Guidance '%s' was previously marked as IRRELEVANT in task '%s'.",
                            $outcome['guidance_id'],
                            $outcome['task_id'] ?? 'unknown',
                        );
                    }
                }
                continue;
            }

            $guidanceUsed = $outcome['guidance_used'] ?? [];
            $appliedProposals = $outcome['applied_proposals'] ?? [];
            
            $intersect = array_intersect($selectedGuidanceIds, array_merge($guidanceUsed, $appliedProposals));
            if ($intersect !== []) {
                $harmful = $outcome['harmful'] ?? [];
                $irrelevant = $outcome['irrelevant'] ?? [];
                
                foreach ($intersect as $gid) {
                    if (in_array($gid, $harmful, true)) {
                        $warnings[] = sprintf(
                            "Guidance '%s' was previously marked as HARMFUL in task '%s'. Reason: %s",
                            $gid,
                            $outcome['task_id'] ?? 'unknown',
                            $outcome['comment'] ?? 'None provided'
                        );
                    }
                    if (in_array($gid, $irrelevant, true)) {
                        $warnings[] = sprintf(
                            "Guidance '%s' was previously marked as IRRELEVANT in task '%s'.",
                            $gid,
                            $outcome['task_id'] ?? 'unknown'
                        );
                    }
                }
            }
        }

        // Check for unknown rule IDs referenced in outcomes
        $allKnownIds = [];
        foreach ($activeGuidance as $g) {
            $allKnownIds[] = $g->id;
        }
        foreach ($constraints as $constraint) {
            $allKnownIds[] = $constraint->id;
            $allKnownIds[] = $constraint->sourceProposal;
        }
        foreach ($rejectedGuidance as $rg) {
            $allKnownIds[] = $rg->id;
        }
        foreach ($outcomes as $outcome) {
            if (isset($outcome['guidance_id']) && is_string($outcome['guidance_id'])) {
                if (!in_array($outcome['guidance_id'], $allKnownIds, true)) {
                    throw new RecallCompilationBlockedException(sprintf("Conflict: outcome references unknown rule ID '%s'.", $outcome['guidance_id']));
                }
            }
            $guidanceUsed = $outcome['guidance_used'] ?? [];
            $appliedProposals = $outcome['applied_proposals'] ?? [];
            foreach (array_merge($guidanceUsed, $appliedProposals) as $refId) {
                if (!in_array($refId, $allKnownIds, true)) {
                    throw new RecallCompilationBlockedException(sprintf("Conflict: outcome references unknown rule ID '%s'.", $refId));
                }
            }
        }

        // 4. Stale or unapproved check
        foreach ($selectedGuidance as $g) {
            if ($g->status !== 'approved' && $g->status !== 'applied') {
                throw new RecallCompilationBlockedException(sprintf("Conflict: guidance '%s' is not approved or applied (status: %s)", $g->id, $g->status));
            }
        }

        // 5. Constraint validation plan check
        foreach ($selectedGuidance as $g) {
            if ($g->targetType === 'constraint' && $g->validation === []) {
                throw new RecallCompilationBlockedException(sprintf("Conflict: constraint '%s' exists but validation plan omits it.", $g->id));
            }
        }

        // 6. Conflict detection: multiple active guidance with identical targets or duplicate directives
        $guidanceByTarget = [];
        $guidanceByDirective = [];
        foreach ($selectedGuidance as $g) {
            if ($g->target !== null && trim($g->target) !== '') {
                $guidanceByTarget[$g->target][] = $g->id;
            }
            if ($g->new !== null) {
                $trimmedNew = trim($g->new);
                if ($trimmedNew !== '') {
                    $guidanceByDirective[$trimmedNew][] = $g->id;
                }
            }
        }
        foreach ($guidanceByTarget as $target => $ids) {
            if (count($ids) > 1) {
                throw new RecallCompilationBlockedException(sprintf(
                    "Conflict: Multiple active guidance items target '%s' (%s).",
                    $target,
                    implode(', ', $ids)
                ));
            }
        }
        foreach ($guidanceByDirective as $directive => $ids) {
            if (count($ids) > 1) {
                throw new RecallCompilationBlockedException(sprintf(
                    "Conflict: Duplicate directive text detected in multiple guidance items (%s).",
                    implode(', ', $ids)
                ));
            }
        }

        // 7. Contradiction warning: selected guidance targets a known rejected proposal target
        foreach ($selectedGuidance as $g) {
            if ($g->target !== null && trim($g->target) !== '') {
                foreach ($rejectedGuidance as $rj) {
                    if ($rj->target !== null && trim($rj->target) !== '' && $g->target === $rj->target) {
                        throw new RecallCompilationBlockedException(sprintf(
                            "Conflict: Selected guidance '%s' targets '%s', which contradicts rejected proposal '%s' (Rejection reason: %s).",
                            $g->id,
                            $g->target,
                            $rj->id,
                            $rj->reason
                        ));
                    }
                }
            }
        }

        // Sort selected items deterministically
        usort($selectedGuidance, static fn(RecallGuidance $a, RecallGuidance $b) => strcmp($a->id, $b->id));
        usort($selectedRejections, static fn(RecallRejection $a, RecallRejection $b) => strcmp($a->id, $b->id));
        usort($selectedConstraints, static fn(ConstraintManifest $a, ConstraintManifest $b) => strcmp($a->id, $b->id));
        usort($evaluatedGuidance, static fn(EvaluatedGuidance $a, EvaluatedGuidance $b) => strcmp($a->guidanceId, $b->guidanceId));

        return new RecallResult($selectedGuidance, $selectedRejections, $warnings, $selectedConstraints, $outcomeStats, $evaluatedGuidance);
    }

    /**
     * @param list<string> $selectedIds
     * @param list<array<string, mixed>> $outcomes
     * @return array<string, array{selected_count: int, helpful_count: int, irrelevant_count: int, harmful_count: int, violation_detected_count: int}>
     */
    private function buildOutcomeStats(array $selectedIds, array $outcomes): array
    {
        $stats = [];
        foreach ($selectedIds as $id) {
            $stats[$id] = [
                'selected_count' => 0,
                'helpful_count' => 0,
                'irrelevant_count' => 0,
                'harmful_count' => 0,
                'violation_detected_count' => 0,
            ];
        }

        foreach ($outcomes as $outcome) {
            if (isset($outcome['guidance_id'], $outcome['outcome']) && is_string($outcome['guidance_id']) && is_string($outcome['outcome'])) {
                $id = $outcome['guidance_id'];
                if (!isset($stats[$id])) {
                    continue;
                }
                $stats[$id]['selected_count']++;
                if ($outcome['outcome'] === OutcomeValue::HELPFUL->value) {
                    $stats[$id]['helpful_count']++;
                }
                if ($outcome['outcome'] === OutcomeValue::IRRELEVANT->value) {
                    $stats[$id]['irrelevant_count']++;
                }
                if ($outcome['outcome'] === OutcomeValue::HARMFUL->value) {
                    $stats[$id]['harmful_count']++;
                }
                continue;
            }

            $selected = $this->stringList($outcome['selected'] ?? array_merge(
                $this->stringList($outcome['guidance_used'] ?? []),
                $this->stringList($outcome['constraints_used'] ?? []),
            ));
            $helpful = $this->stringList($outcome['helpful'] ?? []);
            $irrelevant = $this->stringList($outcome['irrelevant'] ?? []);
            $harmful = $this->stringList($outcome['harmful'] ?? []);
            $referenced = array_values(array_unique([...$selected, ...$helpful, ...$irrelevant, ...$harmful]));

            foreach ($selected as $id) {
                if (isset($stats[$id])) {
                    $stats[$id]['selected_count']++;
                }
            }
            foreach ($helpful as $id) {
                if (isset($stats[$id])) {
                    $stats[$id]['helpful_count']++;
                }
            }
            foreach ($irrelevant as $id) {
                if (isset($stats[$id])) {
                    $stats[$id]['irrelevant_count']++;
                }
            }
            foreach ($harmful as $id) {
                if (isset($stats[$id])) {
                    $stats[$id]['harmful_count']++;
                }
            }
            if (($outcome['result'] ?? null) === 'violation_detected') {
                foreach ($referenced as $id) {
                    if (isset($stats[$id])) {
                        $stats[$id]['violation_detected_count']++;
                    }
                }
            }
        }

        return $stats;
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }

    /**
     * @param list<string> $guidanceScopes
     * @param list<string> $taskFiles
     * @return list<string>
     */
    private function matchingTaskFiles(array $guidanceScopes, array $taskFiles): array
    {
        if (!$this->matchesAnyScope($guidanceScopes, $taskFiles)) {
            return [];
        }
        if ($this->isGlobalScope($guidanceScopes) || $guidanceScopes === [] || $taskFiles === []) {
            return $taskFiles;
        }

        $matched = [];
        foreach ($guidanceScopes as $scope) {
            $scope = trim($scope);
            if ($scope === '') {
                continue;
            }
            $scopeNormalized = rtrim(str_replace('\\', '/', $scope), '/');
            foreach ($taskFiles as $taskFile) {
                $taskFileNormalized = rtrim(str_replace('\\', '/', trim($taskFile)), '/');
                if (
                    $taskFileNormalized === $scopeNormalized
                    ||
                    str_starts_with($taskFileNormalized, $scopeNormalized . '/')
                    ||
                    str_starts_with($scopeNormalized, $taskFileNormalized . '/')
                ) {
                    $matched[] = $taskFile;
                }
            }
        }

        return array_values(array_unique($matched));
    }

    /**
     * @param list<string> $scope
     */
    private function selectionReason(array $scope): SelectionReason
    {
        return $this->isGlobalScope($scope) || $scope === [] ? SelectionReason::GLOBAL : SelectionReason::SCOPE_OVERLAP;
    }

    /**
     * @param list<string> $scope
     */
    private function isGlobalScope(array $scope): bool
    {
        foreach ($scope as $scopePrefix) {
            $normalized = trim($scopePrefix);
            if ($normalized === '/' || $normalized === '*' || $normalized === '') {
                return true;
            }
        }

        return false;
    }

    private function guidanceType(?string $targetType, string $id): GuidanceType
    {
        return GuidanceType::fromTargetType($targetType, $id);
    }

    /**
     * @param list<string> $guidanceScopes
     * @param list<string> $taskFiles
     */
    private function matchesAnyScope(array $guidanceScopes, array $taskFiles): bool
    {
        // If guidance has no scope, treat as global
        if ($guidanceScopes === []) {
            return true;
        }

        foreach ($guidanceScopes as $gs) {
            $gs = trim($gs);
            if ($gs === '/' || $gs === '*' || $gs === '') {
                return true;
            }

            // If task files list is empty, we default to selecting matches to avoid missing important info
            if ($taskFiles === []) {
                return true;
            }

            foreach ($taskFiles as $tf) {
                $tf = trim($tf);
                if ($tf === '/' || $tf === '*' || $tf === '') {
                    return true;
                }

                if ($tf === $gs) {
                    return true;
                }

                // Check directory prefix matches
                $gsNormalized = rtrim(str_replace('\\', '/', $gs), '/');
                $tfNormalized = rtrim(str_replace('\\', '/', $tf), '/');

                if (str_starts_with($tfNormalized, $gsNormalized . '/')) {
                    return true;
                }
                if (str_starts_with($gsNormalized, $tfNormalized . '/')) {
                    return true;
                }
            }
        }

        return false;
    }
}
