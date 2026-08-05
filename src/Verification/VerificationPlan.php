<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Verification;

final readonly class VerificationPlan
{
    /**
     * @param array<string, string>|null $analysisFingerprint
     * @param list<KnowledgeProbe> $knowledgeProbes
     * @param list<array{kind: string, evidence_ids: non-empty-list<string>, source_ref: string, reason: string}> $omittedProbeCandidates
     * @param list<ChecklistItem> $checklist
     * @param list<ObjectiveGate> $objectiveGates
     * @param non-empty-list<string> $requiredEvidenceTypes
     * @param array{name: string, version: string, seed_sha256: string} $generator
     */
    public function __construct(
        public string $taskId,
        public string $target,
        public string $mapDigest,
        public ?array $analysisFingerprint,
        public array $knowledgeProbes,
        public array $omittedProbeCandidates,
        public array $checklist,
        public array $objectiveGates,
        public array $requiredEvidenceTypes,
        public array $generator,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => '1.0',
            'task_id' => $this->taskId,
            'target' => $this->target,
            'map_digest' => $this->mapDigest,
            'analysis_fingerprint' => $this->analysisFingerprint,
            'knowledge_probes' => array_map(
                static fn (KnowledgeProbe $probe): array => $probe->toArray(),
                $this->knowledgeProbes,
            ),
            'omitted_probe_candidates' => $this->omittedProbeCandidates,
            'checklist' => array_map(
                static fn (ChecklistItem $item): array => $item->toArray(),
                $this->checklist,
            ),
            'objective_gates' => array_map(
                static fn (ObjectiveGate $gate): array => $gate->toArray(),
                $this->objectiveGates,
            ),
            'required_evidence_types' => $this->requiredEvidenceTypes,
            'generator' => $this->generator,
        ];
    }
}
