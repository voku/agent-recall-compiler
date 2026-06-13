<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler;

final class RecallDecisionEngine
{
    /**
     * @param list<RecallGuidance> $activeGuidance
     * @param list<RecallRejection> $rejectedGuidance
     * @param list<array<string, mixed>> $outcomes
     * @return RecallResult
     */
    public function decide(
        TaskBrief $task,
        array $activeGuidance,
        array $rejectedGuidance,
        array $outcomes
    ): RecallResult {
        $selectedGuidance = [];
        $selectedRejections = [];
        $warnings = [];

        // 1. Select active guidance matching task files
        foreach ($activeGuidance as $g) {
            if ($this->matchesAnyScope($g->scope, $task->files)) {
                $selectedGuidance[] = $g;
            }
        }

        // 2. Select matching rejections to warn about past mistakes
        foreach ($rejectedGuidance as $rg) {
            if ($this->matchesAnyScope($rg->scope, $task->files)) {
                $selectedRejections[] = $rg;
            }
        }

        // 3. Process outcomes for selected guidance
        $selectedGuidanceIds = array_map(static fn(RecallGuidance $g) => $g->id, $selectedGuidance);
        foreach ($outcomes as $outcome) {
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

        // 4. Conflict detection: multiple active guidance with identical targets or duplicate directives
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
                $warnings[] = sprintf(
                    "Conflict: Multiple active guidance items target '%s' (%s).",
                    $target,
                    implode(', ', $ids)
                );
            }
        }
        foreach ($guidanceByDirective as $directive => $ids) {
            if (count($ids) > 1) {
                $warnings[] = sprintf(
                    "Conflict: Duplicate directive text detected in multiple guidance items (%s).",
                    implode(', ', $ids)
                );
            }
        }

        // 5. Contradiction warning: selected guidance targets a known rejected proposal target
        foreach ($selectedGuidance as $g) {
            if ($g->target !== null && trim($g->target) !== '') {
                foreach ($rejectedGuidance as $rj) {
                    if ($rj->target !== null && trim($rj->target) !== '' && $g->target === $rj->target) {
                        $warnings[] = sprintf(
                            "Selected guidance '%s' targets '%s', which matches the target of rejected proposal '%s' (Rejection reason: %s).",
                            $g->id,
                            $g->target,
                            $rj->id,
                            $rj->reason
                        );
                    }
                }
            }
        }

        // Sort selected items deterministically
        usort($selectedGuidance, static fn(RecallGuidance $a, RecallGuidance $b) => strcmp($a->id, $b->id));
        usort($selectedRejections, static fn(RecallRejection $a, RecallRejection $b) => strcmp($a->id, $b->id));

        return new RecallResult($selectedGuidance, $selectedRejections, $warnings);
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
