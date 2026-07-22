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
        $memory = trim($this->repository->loadMemory($rootConfig->root));
        if ($memory === '') {
            return new RecallProviderResult(CanonicalJson::digest(['memory' => '']));
        }

        $payload = ['content' => $memory];

        return new RecallProviderResult(
            CanonicalJson::digest($payload),
            [new RecallFact('memory.global', 'memory', 'repository_memory', 'MEMORY.md', ['/'], $payload)],
        );
    }
}
