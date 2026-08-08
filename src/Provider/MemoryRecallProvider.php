<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Provider;

use voku\AgentRecallCompiler\CanonicalJson;
use voku\AgentRecallCompiler\RecallRepository;
use voku\AgentRecallCompiler\RecallRootConfig;
use voku\AgentRecallCompiler\TaskBrief;

/**
 * Compatibility provider for repository global memory. It is intentionally a
 * distinct provider so its legacy whole-file behavior can later be replaced by
 * scoped memory chunks without changing the compiler pipeline.
 */
final class MemoryRecallProvider implements RecallProvider
{
    public function __construct(private readonly RecallRepository $repository = new RecallRepository())
    {
    }

    public function manifest(): RecallProviderManifest
    {
        return new RecallProviderManifest('memory', '1.0', ['MEMORY.md'], required: false);
    }

    public function collect(TaskBrief $task, RecallRootConfig $rootConfig): RecallProviderResult
    {
        $loaded = $this->loadMemory($rootConfig);
        $memory = trim($loaded['content']);
        if ($memory === '') {
            return new RecallProviderResult(CanonicalJson::digest(['memory' => '']));
        }

        $payload = ['content' => $memory];
        if ($loaded['sourceSha256'] !== null) {
            $payload['canonical_source_ref'] = 'MEMORY.md';
            $payload['source_sha256'] = $loaded['sourceSha256'];
        }

        return new RecallProviderResult(
            CanonicalJson::digest($payload),
            [new RecallFact('memory.global', 'memory', 'repository_memory', 'MEMORY.md', ['/'], $payload)],
        );
    }

    /** @return array{content: string, sourceSha256: string|null} */
    private function loadMemory(RecallRootConfig $rootConfig): array
    {
        if ($rootConfig->projectRoot === null) {
            return [
                'content' => $this->repository->loadMemory($rootConfig->root),
                'sourceSha256' => null,
            ];
        }

        $path = rtrim($rootConfig->projectRoot, '/\\') . '/MEMORY.md';
        if (!is_file($path)) {
            return ['content' => '', 'sourceSha256' => null];
        }

        $content = file_get_contents($path);
        $sha256 = hash_file('sha256', $path);
        if ($content === false || $sha256 === false) {
            return ['content' => '', 'sourceSha256' => null];
        }

        return ['content' => $content, 'sourceSha256' => $sha256];
    }
}
