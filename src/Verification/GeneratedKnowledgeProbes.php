<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Verification;

final readonly class GeneratedKnowledgeProbes
{
    /**
     * @param list<KnowledgeProbe> $probes
     * @param array<string, ProbeAnswer> $answers
     * @param list<array{kind: string, evidence_ids: non-empty-list<string>, source_ref: string, reason: string}> $omittedCandidates
     */
    public function __construct(
        public array $probes,
        public array $answers,
        public array $omittedCandidates,
        public string $seedSha256,
        public string $generatorVersion,
    ) {
    }
}
