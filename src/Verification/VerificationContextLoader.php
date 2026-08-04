<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Verification;

use RuntimeException;
use voku\AgentMap\Context\EditContextPlanner;
use voku\AgentMap\Context\EditContextPolicy;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\FileEntry;
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
    ): VerificationContext {
        $storedMap = $this->reader->read($indexPath);
        $runtimeMap = $this->withRuntimeRoot($storedMap, $sourceRoot);
        $context = $this->planner->plan($runtimeMap, $target, $policy);

        return new VerificationContext($runtimeMap, $context);
    }

    private function withRuntimeRoot(AgentMapIndex $map, ?string $sourceRoot): AgentMapIndex
    {
        $root = ($sourceRoot === null || trim($sourceRoot) === '')
            ? $map->root
            : rtrim($sourceRoot, '/\\');

        $files = [];
        foreach ($map->files as $file) {
            $files[] = $this->upgradeLegacyHash($file, $root);
        }

        return new AgentMapIndex(
            schemaVersion: $map->schemaVersion,
            root: $root,
            backend: $map->backend,
            files: $files,
            relations: $map->relations,
            diagnostics: $map->diagnostics,
            fingerprint: $map->fingerprint,
        );
    }

    private function upgradeLegacyHash(FileEntry $file, string $root): FileEntry
    {
        $prefix = 'legacy-sha1:';
        if (!str_starts_with($file->sha256, $prefix)) {
            return $file;
        }

        $absolute = $root . '/' . $file->path;
        $expectedSha1 = substr($file->sha256, strlen($prefix));
        $actualSha1 = is_file($absolute) ? sha1_file($absolute) : false;
        if (!is_string($actualSha1) || !hash_equals($expectedSha1, $actualSha1)) {
            return $file;
        }

        $sha256 = hash_file('sha256', $absolute);
        if (!is_string($sha256)) {
            throw new RuntimeException('cannot hash mapped source file: ' . $absolute);
        }

        return new FileEntry(
            path: $file->path,
            sha256: 'sha256:' . $sha256,
            namespace: $file->namespace,
            symbols: $file->symbols,
            semanticStatus: $file->semanticStatus,
        );
    }
}
