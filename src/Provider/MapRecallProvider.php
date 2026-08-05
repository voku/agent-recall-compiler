<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Provider;

use RuntimeException;
use voku\AgentMap\Context\EditContextPlanner;
use voku\AgentMap\Context\EditContextPolicy;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\FileEntry;
use voku\AgentMap\Index\IndexReader;
use voku\AgentMap\Search\Embedding\CorpusEmbeddingProvider;
use voku\AgentMap\Search\HybridSearch;
use voku\AgentMap\Search\SearchIndexStore;
use voku\AgentRecallCompiler\RecallRootConfig;
use voku\AgentRecallCompiler\TaskBrief;

/**
 * Read-only adapter for agent-map indexes. agent-map owns decoding,
 * freshness checks, target resolution, relation traversal, and source slicing;
 * recall only turns those deterministic results into provider facts.
 */
final readonly class MapRecallProvider implements RecallProvider
{
    /**
     * A brief shorter than this is a label, not a query. Running hybrid search on "fix bug" returns
     * whatever the corpus happens to weight highest and would present it as a lead.
     */
    private const MIN_QUERY_LENGTH = 12;

    public function __construct(
        private string $indexPath,
        private ?string $sourceRoot = null,
        private EditContextPolicy $policy = new EditContextPolicy(),
        private IndexReader $reader = new IndexReader(),
        private EditContextPlanner $planner = new EditContextPlanner(),
        private ?string $searchDatabase = null,
        private int $searchLimit = 8,
    ) {
    }

    public function manifest(): RecallProviderManifest
    {
        return new RecallProviderManifest(
            'agent-map',
            // The contract version tracks the fact shapes this instance can emit, and a configured
            // search index adds one. A compilation that never enabled search keeps the 2.0 snapshot
            // it had before, so its bundle digest does not move for a feature it does not use.
            $this->searchDatabase === null ? '2.0' : '2.1',
            array_values(array_filter([$this->indexPath, $this->sourceRoot, $this->searchDatabase])),
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

        foreach ($this->searchFacts($runtimeMap, $task) as $searchFact) {
            $facts[] = $searchFact;
        }

        $sourceDigest = hash_file('sha256', $this->indexPath);
        if (!is_string($sourceDigest)) {
            throw new RuntimeException('cannot hash agent-map index: ' . $this->indexPath);
        }

        return new RecallProviderResult('sha256:' . $sourceDigest, $facts);
    }

    /**
     * Ranked candidates from agent-map's derived hybrid-search index.
     *
     * Explicitly opt-in and explicitly separate from the exact facts above: `edit_context` and
     * `navigation` are resolved through the canonical map, this is a ranking. It never widens the
     * effective task scope, and every reason it cannot produce candidates is emitted as a status
     * fact instead of being dropped - a silently absent candidate list is indistinguishable from
     * "the search found nothing", which is a different answer.
     *
     * @return list<RecallFact>
     */
    private function searchFacts(AgentMapIndex $map, TaskBrief $task): array
    {
        if ($this->searchDatabase === null) {
            return [];
        }

        $query = trim($task->description);
        if (strlen($query) < self::MIN_QUERY_LENGTH) {
            return [$this->searchStatusFact('skipped', 'task description is too short to be a search query')];
        }
        if (!is_file($this->searchDatabase)) {
            return [$this->searchStatusFact('missing', 'no search index at ' . $this->searchDatabase . '; run "agent-map search-index build"')];
        }
        if (!SearchIndexStore::supportsFts5()) {
            return [$this->searchStatusFact('unavailable', 'this PHP build has no SQLite FTS5 support')];
        }

        $store = new SearchIndexStore($this->searchDatabase);
        $mapSnapshot = $map->fingerprint === null ? 'sha256:none' : $map->fingerprint->sourceDigest;
        $indexSnapshot = $store->meta('map_snapshot') ?? 'sha256:none';
        if ($indexSnapshot !== $mapSnapshot) {
            return [$this->searchStatusFact(
                'stale',
                'the search index was built from a different map; run "agent-map search-index refresh"',
                ['map_snapshot' => $mapSnapshot, 'search_index_snapshot' => $indexSnapshot],
            )];
        }

        $result = (new HybridSearch(embeddings: $this->corpusProvider($store)))
            ->search($map, $store, $query, $this->searchLimit);

        /** @var list<array<string, mixed>> $hits */
        $hits = $result['results'];
        $scope = [];
        foreach ($hits as $hit) {
            if (is_string($hit['file_path'])) {
                $scope[$hit['file_path']] = true;
            }
        }
        $paths = array_keys($scope);
        sort($paths, SORT_STRING);

        return [new RecallFact(
            id: 'map.search.candidates',
            type: 'navigation_candidates',
            authority: 'derived_navigation',
            sourceRef: $this->searchDatabase,
            scope: $paths,
            payload: [
                'status' => 'ranked',
                'query' => $query,
                'limit' => $this->searchLimit,
                'effective_mode' => $result['effective_mode'],
                'degraded' => $result['degraded'],
                'degraded_reason' => $result['degraded_reason'],
                'structural_terms' => $result['structural_terms'],
                'map_snapshot' => $mapSnapshot,
                'search_index_snapshot' => $indexSnapshot,
                'results' => $hits,
            ],
        )];
    }

    /** @param array<string, string> $extra */
    private function searchStatusFact(string $status, string $reason, array $extra = []): RecallFact
    {
        return new RecallFact(
            id: 'map.search.status',
            type: 'navigation_candidates',
            authority: 'derived_navigation',
            sourceRef: (string) $this->searchDatabase,
            scope: [],
            payload: ['status' => $status, 'reason' => $reason] + $extra,
        );
    }

    /**
     * Restores the weighting the stored vectors were written with, exactly as the agent-map CLI
     * does. Refitting here would produce a different vector space and silently compare query
     * vectors against neighbours that were never in it; a null provider degrades to
     * structural+lexical and says so in the fact payload.
     */
    private function corpusProvider(SearchIndexStore $store): ?CorpusEmbeddingProvider
    {
        if (!$store->enableVectorSupport() || $store->vectorCount() === 0) {
            return null;
        }

        $state = json_decode((string) $store->meta('embedding_state'), true);
        if (!is_array($state) || !is_string($state['revision'] ?? null) || !is_array($state['weights'] ?? null)) {
            return null;
        }

        $provider = new CorpusEmbeddingProvider();
        /** @var array{revision: string, weights: array<string, float>} $state */
        $provider->restore($state);

        return $provider->model()->fingerprint() === $store->meta('embedding_fingerprint') ? $provider : null;
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
     * agent-map can decode schema-1 entries, but its freshness check is
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
