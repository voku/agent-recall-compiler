<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Verification;

final readonly class KnowledgeProbe
{
    /**
     * @param list<string> $evidenceIds
     * @param array<string, string|int|bool|list<string>> $provenance
     * @param non-empty-list<string> $requiredEvidenceTypes
     */
    public function __construct(
        public string $id,
        public string $kind,
        public string $target,
        public string $question,
        public string $answerFormat,
        public array $evidenceIds,
        public array $provenance,
        public array $requiredEvidenceTypes = ['agent_map'],
        public bool $required = true,
    ) {
    }

    /**
     * @return array{
     *     id: string,
     *     kind: string,
     *     target: string,
     *     question: string,
     *     answer_format: string,
     *     evidence_ids: list<string>,
     *     provenance: array<string, string|int|bool|list<string>>,
     *     required_evidence_types: non-empty-list<string>,
     *     required: bool
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'target' => $this->target,
            'question' => $this->question,
            'answer_format' => $this->answerFormat,
            'evidence_ids' => $this->evidenceIds,
            'provenance' => $this->provenance,
            'required_evidence_types' => $this->requiredEvidenceTypes,
            'required' => $this->required,
        ];
    }
}
