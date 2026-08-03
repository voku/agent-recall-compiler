<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Provider;

use RuntimeException;
use voku\AgentMap\Context\EditContextPlanner;
use voku\AgentMap\Context\EditContextPolicy;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\FileEntry;
use voku\AgentMap\Index\IndexReader;
use voku\AgentRecallCompiler\RecallRootConfig;
use voku\AgentRecallCompiler\TaskBrief;

/**
 * Read-only adapter for agent-map 0.2 indexes. agent-map owns decoding,
 * freshness checks, target resolution, relation traversal, and source slicing;
 * recall only turns those deterministic results into provider facts.
 */
final readonly class MapRecallProvider implements RecallProvider
{
    public function __construct(
        private string $indexPath,
        private ?string $sourceRoot = null,
        private EditContextPolicy $policy = new EditContextPolicy(),
        private IndexReader $reader = new IndexReader(),
        private EditContextPlanner $planner = new EditContextPlanner(),
    ) {
    }

    public function manifest(): RecallProviderManifest
    {
        return new RecallProviderManifest(
            'agent-map',
            '2.0',
            array_values(array_filter([$this->indexPath, $this->sourceRoot])),
            required: false,
        );
    }

    public function collect(TaskBrief $task, RecallRootConfig $rootConfig): RecallProviderResult
    {
        $map = $this->reader->read($this->indexPath);
        $runtimeMap = $this->withRuntimeRoot($map);
        $facts = [$this->snapshotFact($map)];

        $filesByPath = [];
        foreach ($runtimeMap->files as $file) {
            $filesByPath[$file->path] = $file;
        }
        ksort($filesByPath, SORT_STRING);

        $staleByPath = [];
        foreach ($runtimeMap->staleEntries() as $stale) {
            $staleByPath[$stale['path']] = $stale['reason'];
        }

        foreach ($task->files as $path) {
            $file = $filesByPath[$path] ?? null;
            if ($file === null) {
                $facts[] = new RecallFact(
                    'map.missing.' . $path,
                    'navigation_status',
                    'derived_navigation',
                    $this->indexPath,
                    [$path],
                    ['path' => $path, 'status' => 'missing'],
                );
                continue;
            }
            if (isset($staleByPath[$path])) {
                $facts[] = new RecallFact(
                    'map.stale.' . $path,
                    'navigation_status',
                    'derived_navigation',
                    $this->indexPath,
                    [$path],
                    ['path' => $path, 'status' => 'stale', 'reason' => $staleByPath[$path]],
                );
                continue;
            }

            $facts[] = $this->fileFact($runtimeMap, $file);
        }

        foreach ($task->targets as $target) {
            $plan = $this->planner->plan($runtimeMap, $target, $this->policy);
            $payload = $plan->toArray();
            $scope = [];
            foreach ($plan->slices as $slice) {
                $scope[$slice->path] = true;
            }
            $paths = array_keys($scope);
            sort($paths, SORT_STRING);

            $facts[] = new RecallFact(
                id: 'map.edit-context.' . $this->factSuffix($target),
                type: 'edit_context',
                authority: 'derived_navigation',
                sourceRef: $this->indexPath . '#' . $target,
                scope: $paths,
                payload: $payload,
            );
        }

        $sourceDigest = hash_file('sha256', $this->indexPath);
        if (!is_string($sourceDigest)) {
            throw new RuntimeException('cannot hash agent-map index: ' . $this->indexPath);
        }

        return new RecallProviderResult('sha256:' . $sourceDigest, $facts);
    }

    private function withRuntimeRoot(AgentMapIndex $map): AgentMapIndex
    {
        $root = ($this->sourceRoot === null || trim($this->sourceRoot) === '')
            ? $map->root
            : rtrim($this->sourceRoot, '/\\');

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

    /**
     * agent-map 0.2 can decode schema-1 entries, but its freshness check is
     * intentionally SHA-256-only. Keep recall's existing file-only contract by
     * upgrading a verified legacy SHA-1 entry in memory. New maps never enter
     * this compatibility path.
     */
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

    private function snapshotFact(AgentMapIndex $map): RecallFact
    {
        return new RecallFact(
            id: 'map.snapshot',
            type: 'navigation_metadata',
            authority: 'derived_navigation',
            sourceRef: $this->indexPath,
            scope: [],
            payload: [
                'schema_version' => $map->schemaVersion,
                'backend' => $map->backend,
                'map_digest' => $map->mapDigest(),
                'fingerprint' => $map->fingerprint?->toArray(),
            ],
        );
    }

    private function fileFact(AgentMapIndex $map, FileEntry $file): RecallFact
    {
        $symbolIds = [];
        foreach ($file->symbols as $symbol) {
            $symbolIds[$symbol->id()] = true;
            foreach ($symbol->methods as $method) {
                $symbolIds[$symbol->methodId($method)] = true;
            }
        }

        $relations = [];
        foreach ($map->relations as $relation) {
            $touchesFile = $relation->file === $file->path || isset($symbolIds[$relation->sourceId]);
            if (!$touchesFile) {
                foreach ($relation->targetIds as $targetId) {
                    if (isset($symbolIds[$targetId])) {
                        $touchesFile = true;
                        break;
                    }
                }
            }
            if ($touchesFile) {
                $relations[] = $relation->toArray();
            }
        }

        $diagnostics = [];
        foreach ($map->diagnostics as $diagnostic) {
            if ($diagnostic->file === $file->path || ($diagnostic->symbolId !== null && isset($symbolIds[$diagnostic->symbolId]))) {
                $diagnostics[] = $diagnostic->toArray();
            }
        }

        $payload = $file->toArray();
        $payload['map_digest'] = $map->mapDigest();
        $payload['relations'] = $relations;
        $payload['diagnostics'] = $diagnostics;

        return new RecallFact(
            id: 'map.file.' . $file->path,
            type: 'navigation',
            authority: 'derived_navigation',
            sourceRef: $this->indexPath,
            scope: [$file->path],
            payload: $payload,
        );
    }

    private function factSuffix(string $value): string
    {
        $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '.', trim($value)));
        $slug = trim($slug, '.');
        if ($slug === '') {
            $slug = 'value';
        }

        return substr($slug, 0, 80) . '.' . substr(hash('sha256', $value), 0, 12);
    }
}
