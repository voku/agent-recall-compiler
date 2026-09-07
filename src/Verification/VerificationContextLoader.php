<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Verification;

use RuntimeException;
use voku\AgentMap\Context\EditContextPlanner;
use voku\AgentMap\Context\EditContextPolicy;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\IndexReader;

/** Loads exactly the map snapshot already selected for target-aware recall. */
final readonly class VerificationContextLoader
{
    public function __construct(
        private IndexReader $reader = new IndexReader(),
        private EditContextPlanner $planner = new EditContextPlanner(),
    ) {
    }

    public function load(
        string $indexPath,
        ?string $sourceRoot,
        EditContextPolicy $policy,
        string $target,
        string $expectedMapDigest,
    ): VerificationContext {
        $storedMap = $this->reader->read($indexPath);
        if (!hash_equals($expectedMapDigest, $storedMap->mapDigest())) {
            throw new RuntimeException('Agent map changed during recall compilation; rebuild the briefing from one snapshot.');
        }
        $runtimeMap = $this->withRuntimeRoot($storedMap, $sourceRoot);
        $context = $this->planner->plan($runtimeMap, $target, $policy);

        return new VerificationContext($runtimeMap, $context);
    }

    private function withRuntimeRoot(AgentMapIndex $map, ?string $sourceRoot): AgentMapIndex
    {
        $root = ($sourceRoot === null || trim($sourceRoot) === '')
            ? $map->root
            : rtrim($sourceRoot, '/\\');

        return new AgentMapIndex(
            schemaVersion: $map->schemaVersion,
            root: $root,
            backend: $map->backend,
            files: $map->files,
            relations: $map->relations,
            diagnostics: $map->diagnostics,
            fingerprint: $map->fingerprint,
        );
    }
}
