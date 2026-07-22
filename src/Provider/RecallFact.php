<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Provider;

final readonly class RecallFact
{
    /**
     * @param list<string> $scope
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $id,
        public string $type,
        public string $authority,
        public string $sourceRef,
        public array $scope,
        public array $payload,
        public ?string $conflictKey = null,
        public int $priority = 0,
        public string $lifecycle = 'active',
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'authority' => $this->authority,
            'source_ref' => $this->sourceRef,
            'scope' => $this->scope,
            'conflict_key' => $this->conflictKey,
            'priority' => $this->priority,
            'lifecycle' => $this->lifecycle,
            'payload' => $this->payload,
        ];
    }
}
