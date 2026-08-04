<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Verification;

final readonly class ProbeCandidate
{
    /**
     * @param non-empty-list<string> $acceptedAnswers
     * @param non-empty-list<string> $evidenceIds
     * @param non-empty-list<string> $reconciliationStates
     * @param list<string> $sourceHashes
     * @param array<string, string|int|bool|list<string>> $provenance
     */
    public function __construct(
        public int $priority,
        public string $kind,
        public string $target,
        public string $question,
        public string $answerFormat,
        public array $acceptedAnswers,
        public array $evidenceIds,
        public array $reconciliationStates,
        public array $sourceHashes,
        public array $provenance,
    ) {
    }

    public function identity(): string
    {
        return hash('sha256', implode("\0", [
            $this->kind,
            $this->target,
            implode(',', $this->acceptedAnswers),
            implode(',', $this->evidenceIds),
        ]));
    }
}
