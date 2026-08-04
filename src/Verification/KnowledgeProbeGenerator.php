<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Verification;

use voku\AgentMap\Context\EditContextPlan;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\RelationEntry;
use voku\AgentRecallCompiler\TaskBrief;

/** Generates bounded, deterministic questions and a separate verifier answer set. */
final readonly class KnowledgeProbeGenerator
{
    public const GENERATOR_VERSION = '1';

    private const MAX_PROBES = 5;

    /** @var non-empty-list<string> */
    private const ELIGIBLE_STATES = ['confirmed', 'semantic_enrichment', 'phpstan_resolved'];

    public function generate(AgentMapIndex $map, TaskBrief $task, EditContextPlan $context): GeneratedKnowledgeProbes
    {
        $target = $context->resolvedTarget;
        $targetId = $target->id;
        $mapDigest = $context->mapDigest;
        $seedSha256 = 'sha256:' . hash('sha256', implode("\0", [
            $task->id,
            $targetId,
            $mapDigest,
            self::GENERATOR_VERSION,
        ]));

        /** @var list<ProbeCandidate> $candidates */
        $candidates = [];
        if (!$this->isEligibleResolvedMethod($target->owner->reconciliationStatus, $target->method->reconciliationStatus)) {
            return new GeneratedKnowledgeProbes(
                probes: [],
                answers: [],
                omittedCandidates: [],
                seedSha256: $seedSha256,
                generatorVersion: self::GENERATOR_VERSION,
            );
        }

        $location = sprintf('%s:%d-%d', $target->file->path, $target->method->lineStart, $target->method->lineEnd);
        $candidates[] = new ProbeCandidate(
            priority: 50,
            kind: 'source_location',
            target: $targetId,
            question: sprintf('Where is the canonical target `%s` declared?', $targetId),
            answerFormat: 'source_location',
            acceptedAnswers: [$location],
            evidenceIds: [$targetId],
            reconciliationStates: $this->sortedUniqueNonEmpty([
                $target->owner->reconciliationStatus,
                $target->method->reconciliationStatus,
            ]),
            sourceHashes: [$target->file->sha256],
            provenance: [
                'source_ref' => $location,
                'relation' => 'declaration',
            ],
        );

        foreach ($map->outgoing($targetId, 'overrides') as $relation) {
            if (!$this->isEligibleRelation($relation)) {
                continue;
            }
            foreach ($relation->targetIds as $contractId) {
                if (!$this->isEligibleMethodId($map, $contractId)) {
                    continue;
                }
                $contract = $map->resolvedMethodById($contractId);
                if ($contract === null) {
                    continue;
                }
                $kind = $contract->owner->kind === 'interface' ? 'implemented_contract' : 'override';
                $candidates[] = $this->relationCandidate(
                    map: $map,
                    relation: $relation,
                    priority: $kind === 'implemented_contract' ? 10 : 20,
                    kind: $kind,
                    targetId: $targetId,
                    question: $kind === 'implemented_contract'
                        ? sprintf('Which canonical contract method is implemented by `%s` at %s?', $targetId, $this->sourceRef($relation))
                        : sprintf('Which canonical parent method is overridden by `%s` at %s?', $targetId, $this->sourceRef($relation)),
                    acceptedAnswer: $contractId,
                );
            }
        }

        foreach ($map->incoming($targetId, 'overrides') as $relation) {
            if (!$this->isEligibleRelation($relation) || !$this->isEligibleMethodId($map, $relation->sourceId)) {
                continue;
            }
            $candidates[] = $this->relationCandidate(
                map: $map,
                relation: $relation,
                priority: 20,
                kind: 'override',
                targetId: $targetId,
                question: sprintf('Which canonical method overrides `%s` at %s?', $targetId, $this->sourceRef($relation)),
                acceptedAnswer: $relation->sourceId,
            );
        }

        foreach ($map->incoming($targetId, 'calls') as $relation) {
            if (!$this->isEligibleRelation($relation) || !$this->isEligibleMethodId($map, $relation->sourceId)) {
                continue;
            }
            $caller = $map->resolvedMethodById($relation->sourceId);
            $callerPath = $caller?->file->path ?? $relation->file;
            $priority = $this->looksLikeTestPath($callerPath) ? 30 : 40;
            $candidates[] = $this->relationCandidate(
                map: $map,
                relation: $relation,
                priority: $priority,
                kind: 'incoming_call',
                targetId: $targetId,
                question: sprintf('Which canonical method directly calls `%s` from %s?', $targetId, $this->sourceRef($relation)),
                acceptedAnswer: $relation->sourceId,
            );
        }

        foreach ($map->outgoing($targetId, 'calls') as $relation) {
            if (!$this->isEligibleRelation($relation)) {
                continue;
            }
            foreach ($relation->targetIds as $calledId) {
                if (!$this->isEligibleMethodId($map, $calledId)) {
                    continue;
                }
                $candidates[] = $this->relationCandidate(
                    map: $map,
                    relation: $relation,
                    priority: 60,
                    kind: 'outgoing_call',
                    targetId: $targetId,
                    question: sprintf('Which canonical method is called by `%s` at %s?', $targetId, $this->sourceRef($relation)),
                    acceptedAnswer: $calledId,
                );
            }
        }

        $candidates = $this->deduplicate($candidates);
        usort($candidates, static function (ProbeCandidate $left, ProbeCandidate $right): int {
            $leftSourceRef = $left->provenance['source_ref'] ?? '';
            $rightSourceRef = $right->provenance['source_ref'] ?? '';
            $leftSourceRef = is_string($leftSourceRef) ? $leftSourceRef : '';
            $rightSourceRef = is_string($rightSourceRef) ? $rightSourceRef : '';

            return $left->priority <=> $right->priority
                ?: $left->kind <=> $right->kind
                ?: $leftSourceRef <=> $rightSourceRef
                ?: $left->identity() <=> $right->identity();
        });

        $selected = array_slice($candidates, 0, self::MAX_PROBES);
        $omitted = array_slice($candidates, self::MAX_PROBES);
        /** @var array<string, int> $kindCounters */
        $kindCounters = [];
        /** @var list<KnowledgeProbe> $probes */
        $probes = [];
        /** @var array<string, ProbeAnswer> $answers */
        $answers = [];
        foreach ($selected as $candidate) {
            $kindCounters[$candidate->kind] = ($kindCounters[$candidate->kind] ?? 0) + 1;
            $id = sprintf(
                'probe:%s:%03d',
                str_replace('_', '-', $candidate->kind),
                $kindCounters[$candidate->kind],
            );
            $probe = new KnowledgeProbe(
                id: $id,
                kind: $candidate->kind,
                target: $candidate->target,
                question: $candidate->question,
                answerFormat: $candidate->answerFormat,
                evidenceIds: $candidate->evidenceIds,
                provenance: $candidate->provenance,
            );
            $probes[] = $probe;
            $answers[$id] = new ProbeAnswer(
                acceptedAnswers: $candidate->acceptedAnswers,
                evidenceIds: $candidate->evidenceIds,
                reconciliationStates: $candidate->reconciliationStates,
                sourceHashes: $candidate->sourceHashes,
            );
        }
        ksort($answers, SORT_STRING);

        /** @var list<array{kind: string, evidence_ids: non-empty-list<string>, source_ref: string, reason: string}> $omittedCandidates */
        $omittedCandidates = [];
        foreach ($omitted as $candidate) {
            $sourceRef = $candidate->provenance['source_ref'] ?? 'unknown';
            $omittedCandidates[] = [
                'kind' => $candidate->kind,
                'evidence_ids' => $candidate->evidenceIds,
                'source_ref' => is_string($sourceRef) ? $sourceRef : 'unknown',
                'reason' => 'maximum probe count reached',
            ];
        }

        return new GeneratedKnowledgeProbes(
            probes: $probes,
            answers: $answers,
            omittedCandidates: $omittedCandidates,
            seedSha256: $seedSha256,
            generatorVersion: self::GENERATOR_VERSION,
        );
    }

    private function relationCandidate(
        AgentMapIndex $map,
        RelationEntry $relation,
        int $priority,
        string $kind,
        string $targetId,
        string $question,
        string $acceptedAnswer,
    ): ProbeCandidate {
        $sourceHashes = $this->sourceHashes($map, $relation, $acceptedAnswer);

        return new ProbeCandidate(
            priority: $priority,
            kind: $kind,
            target: $targetId,
            question: $question,
            answerFormat: 'canonical_symbol_ids',
            acceptedAnswers: [$acceptedAnswer],
            evidenceIds: [$relation->id],
            reconciliationStates: [$relation->resolution],
            sourceHashes: $sourceHashes,
            provenance: [
                'source_ref' => $this->sourceRef($relation),
                'relation_kind' => $relation->kind,
                'resolution' => $relation->resolution,
            ],
        );
    }

    /** @return list<string> */
    private function sourceHashes(AgentMapIndex $map, RelationEntry $relation, string $acceptedAnswer): array
    {
        $hashes = [];
        $relationFile = $map->file($relation->file);
        if ($relationFile !== null) {
            $hashes[] = $relationFile->sha256;
        }
        foreach ([$relation->sourceId, $acceptedAnswer] as $symbolId) {
            $method = $map->resolvedMethodById($symbolId);
            if ($method !== null) {
                $hashes[] = $method->file->sha256;
            }
        }

        return $this->sortedUnique($hashes);
    }

    private function sourceRef(RelationEntry $relation): string
    {
        return sprintf('%s:%d-%d', $relation->file, $relation->lineStart, $relation->lineEnd);
    }

    private function isEligibleRelation(RelationEntry $relation): bool
    {
        return $this->isEligibleState($relation->resolution);
    }

    private function isEligibleMethodId(AgentMapIndex $map, string $symbolId): bool
    {
        if (!$this->isCanonicalMethodId($symbolId)) {
            return false;
        }

        $method = $map->resolvedMethodById($symbolId);
        if ($method === null) {
            return false;
        }

        return $this->isEligibleResolvedMethod(
            $method->owner->reconciliationStatus,
            $method->method->reconciliationStatus,
        );
    }

    private function isEligibleResolvedMethod(string $ownerState, string $methodState): bool
    {
        return $this->isEligibleState($ownerState) && $this->isEligibleState($methodState);
    }

    private function isCanonicalMethodId(string $symbolId): bool
    {
        return str_starts_with($symbolId, 'method:') && str_contains($symbolId, '::');
    }

    private function isEligibleState(string $state): bool
    {
        return in_array($state, self::ELIGIBLE_STATES, true);
    }

    private function looksLikeTestPath(string $path): bool
    {
        $path = strtolower(str_replace('\\', '/', $path));

        return str_contains($path, '/test/')
            || str_contains($path, '/tests/')
            || str_contains($path, '/spec/')
            || str_contains($path, '/specs/')
            || str_ends_with($path, 'test.php')
            || str_ends_with($path, 'cest.php');
    }

    /**
     * @param list<ProbeCandidate> $candidates
     * @return list<ProbeCandidate>
     */
    private function deduplicate(array $candidates): array
    {
        /** @var array<string, ProbeCandidate> $unique */
        $unique = [];
        foreach ($candidates as $candidate) {
            $unique[$candidate->identity()] = $candidate;
        }

        return array_values($unique);
    }

    /**
     * @param non-empty-list<string> $values
     * @return non-empty-list<string>
     */
    private function sortedUniqueNonEmpty(array $values): array
    {
        $values = array_values(array_unique($values));
        sort($values, SORT_STRING);

        return $values;
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function sortedUnique(array $values): array
    {
        $values = array_values(array_unique($values));
        sort($values, SORT_STRING);

        return $values;
    }
}
