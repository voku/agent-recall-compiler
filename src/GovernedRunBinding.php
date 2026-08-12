<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler;

use InvalidArgumentException;

final readonly class GovernedRunBinding
{
    public function __construct(
        public string $runId,
        public int $contractRevision,
        public string $contractSource,
        public string $contractSha256,
    ) {
        if (trim($this->runId) === '') {
            throw new InvalidArgumentException('governed run_id must be non-empty');
        }
        if ($this->contractRevision < 1) {
            throw new InvalidArgumentException('governed contract revision must be positive');
        }
        if (trim($this->contractSource) === '') {
            throw new InvalidArgumentException('governed contract source must be non-empty');
        }
        if (preg_match('/^sha256:[a-f0-9]{64}$/', $this->contractSha256) !== 1) {
            throw new InvalidArgumentException('governed contract sha256 must use sha256:<64 lowercase hex>');
        }
    }

    /** @return array{run_id: string, contract_revision: int, contract_source: string, contract_sha256: string} */
    public function toArray(): array
    {
        return [
            'run_id' => $this->runId,
            'contract_revision' => $this->contractRevision,
            'contract_source' => $this->contractSource,
            'contract_sha256' => $this->contractSha256,
        ];
    }
}
