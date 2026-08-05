<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Verification;

final readonly class ProbeAnswer
{
    /**
     * @param non-empty-list<string> $acceptedAnswers
     * @param non-empty-list<string> $evidenceIds
     * @param non-empty-list<string> $reconciliationStates
     * @param list<string> $sourceHashes
     */
    public function __construct(
        public array $acceptedAnswers,
        public array $evidenceIds,
        public array $reconciliationStates,
        public array $sourceHashes,
    ) {
    }

    /**
     * @return array{
     *     accepted_answers: non-empty-list<string>,
     *     evidence_ids: non-empty-list<string>,
     *     reconciliation_states: non-empty-list<string>,
     *     source_hashes: list<string>
     * }
     */
    public function toArray(): array
    {
        return [
            'accepted_answers' => $this->acceptedAnswers,
            'evidence_ids' => $this->evidenceIds,
            'reconciliation_states' => $this->reconciliationStates,
            'source_hashes' => $this->sourceHashes,
        ];
    }
}
