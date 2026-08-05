<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Verification;

final readonly class ChecklistItem
{
    /**
     * @param non-empty-list<string> $evidenceIds
     * @param array<string, string|int|bool|list<string>> $provenance
     */
    public function __construct(
        public string $id,
        public string $statement,
        public string $verifier,
        public array $evidenceIds,
        public array $provenance,
        public bool $required = true,
    ) {
    }

    /**
     * @return array{
     *     id: string,
     *     statement: string,
     *     verifier: string,
     *     evidence_ids: non-empty-list<string>,
     *     provenance: array<string, string|int|bool|list<string>>,
     *     required: bool
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'statement' => $this->statement,
            'verifier' => $this->verifier,
            'evidence_ids' => $this->evidenceIds,
            'provenance' => $this->provenance,
            'required' => $this->required,
        ];
    }
}
