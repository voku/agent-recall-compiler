<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Provider;

final readonly class RecallProviderManifest
{
    /** @param list<string> $sourcePaths */
    public function __construct(
        public string $id,
        public string $contractVersion,
        public array $sourcePaths,
        public bool $required = true,
    ) {
    }

    /** @return array{id: string, contract_version: string, source_paths: list<string>, required: bool} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'contract_version' => $this->contractVersion,
            'source_paths' => $this->sourcePaths,
            'required' => $this->required,
        ];
    }
}
