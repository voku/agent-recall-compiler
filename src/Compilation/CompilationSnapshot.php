<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Compilation;

use voku\AgentRecallCompiler\CanonicalJson;
use voku\AgentRecallCompiler\Provider\RecallProviderManifest;

final readonly class CompilationSnapshot
{
    /**
     * @param list<array{manifest: array{id: string, contract_version: string, source_paths: list<string>, required: bool}, source_digest: string}> $providers
     */
    public function __construct(
        public string $taskDigest,
        public array $providers,
    ) {
    }

    /** @return array{schema_version: string, task_digest: string, providers: list<array{manifest: array{id: string, contract_version: string, source_paths: list<string>, required: bool}, source_digest: string}>} */
    public function toArray(): array
    {
        return [
            'schema_version' => '1.0',
            'task_digest' => $this->taskDigest,
            'providers' => $this->providers,
        ];
    }

    public function digest(): string
    {
        return CanonicalJson::digest($this->toArray());
    }
}
