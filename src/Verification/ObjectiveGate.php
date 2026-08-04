<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Verification;

final readonly class ObjectiveGate
{
    /** @param array<string, string|int|bool> $provenance */
    public function __construct(
        public string $id,
        public string $kind,
        public array $provenance = [],
        public bool $required = true,
    ) {
    }

    /** @return array{id: string, kind: string, provenance: array<string, string|int|bool>, required: bool} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'provenance' => $this->provenance,
            'required' => $this->required,
        ];
    }
}
