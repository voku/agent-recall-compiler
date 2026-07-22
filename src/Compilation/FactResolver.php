<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Compilation;

use voku\AgentRecallCompiler\CanonicalJson;
use voku\AgentRecallCompiler\Provider\RecallFact;
use voku\AgentRecallCompiler\RecallCompilationBlockedException;

/**
 * Resolves only explicit fact conflicts. It never infers relevance or asks a
 * model to reconcile prose: providers must either provide a precedence signal
 * or leave the conflicting source out of the task's scope.
 */
final class FactResolver
{
    /** @param list<RecallFact> $facts */
    public function resolve(array $facts): FactResolution
    {
        $independent = [];
        $byConflictKey = [];
        foreach ($facts as $fact) {
            if ($fact->conflictKey === null || $fact->conflictKey === '') {
                $independent[] = $fact;
                continue;
            }
            $byConflictKey[$fact->conflictKey][] = $fact;
        }

        $selected = $independent;
        $decisions = [];
        ksort($byConflictKey, SORT_STRING);
        foreach ($byConflictKey as $conflictKey => $candidates) {
            usort($candidates, fn (RecallFact $left, RecallFact $right): int => $this->compare($left, $right));
            $winner = $candidates[0];
            $samePrecedence = array_values(array_filter(
                $candidates,
                fn (RecallFact $fact): bool => $this->precedence($fact) === $this->precedence($winner),
            ));
            foreach (array_slice($samePrecedence, 1) as $candidate) {
                if (CanonicalJson::encode($candidate->payload) !== CanonicalJson::encode($winner->payload)) {
                    throw new RecallCompilationBlockedException(sprintf(
                        'Unresolved fact conflict for "%s": "%s" and "%s" have equal authority and priority.',
                        $conflictKey,
                        $winner->id,
                        $candidate->id,
                    ));
                }
            }

            $selected[] = $winner;
            $superseded = array_map(
                static fn (RecallFact $fact): string => $fact->id,
                array_slice($candidates, 1),
            );
            $decisions[] = [
                'conflict_key' => $conflictKey,
                'selected_id' => $winner->id,
                'superseded_ids' => $superseded,
                'reason' => $superseded === []
                    ? 'single candidate'
                    : 'higher explicit priority or authority precedence',
            ];
        }

        usort($selected, static fn (RecallFact $left, RecallFact $right): int => $left->id <=> $right->id);

        return new FactResolution(
            array_map(static fn (RecallFact $fact): array => $fact->toArray(), $selected),
            $decisions,
        );
    }

    private function compare(RecallFact $left, RecallFact $right): int
    {
        $leftPrecedence = $this->precedence($left);
        $rightPrecedence = $this->precedence($right);
        if ($leftPrecedence !== $rightPrecedence) {
            return $rightPrecedence <=> $leftPrecedence;
        }

        $source = $left->sourceRef <=> $right->sourceRef;
        if ($source !== 0) {
            return $source;
        }

        return $left->id <=> $right->id;
    }

    private function precedence(RecallFact $fact): int
    {
        return ($fact->priority * 1000) + match ($fact->authority) {
            'hard_constraint' => 900,
            'approved_session_brief' => 800,
            'task_input' => 700,
            'kanban_board' => 600,
            'project_adr' => 500,
            'approved_learning' => 400,
            'project_skill' => 300,
            'repository_memory' => 200,
            'derived_navigation' => 100,
            default => 0,
        };
    }
}
