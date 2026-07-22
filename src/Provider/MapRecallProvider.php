<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Provider;

use RuntimeException;
use voku\AgentRecallCompiler\CanonicalJson;
use voku\AgentRecallCompiler\RecallRootConfig;
use voku\AgentRecallCompiler\TaskBrief;

/**
 * Read-only adapter for the stable JSON produced by agent-map. Only exact
 * task-file entries are emitted, and stale entries become explicit facts
 * instead of silently rebuilding the index.
 */
final class MapRecallProvider implements RecallProvider
{
    public function __construct(private readonly string $indexPath, private readonly ?string $sourceRoot = null)
    {
    }

    public function manifest(): RecallProviderManifest
    {
        return new RecallProviderManifest('agent-map', '1.0', array_values(array_filter([$this->indexPath, $this->sourceRoot])), required: false);
    }

    public function collect(TaskBrief $task, RecallRootConfig $rootConfig): RecallProviderResult
    {
        if (!is_file($this->indexPath)) {
            throw new RuntimeException('agent-map index not found: ' . $this->indexPath);
        }
        $json = file_get_contents($this->indexPath);
        if ($json === false) {
            throw new RuntimeException('cannot read agent-map index: ' . $this->indexPath);
        }
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('invalid agent-map index: ' . $exception->getMessage());
        }
        if (!is_array($data) || !is_array($data['files'] ?? null)) {
            throw new RuntimeException('agent-map index has no files array: ' . $this->indexPath);
        }

        $byPath = [];
        foreach ($data['files'] as $file) {
            if (is_array($file) && is_string($file['path'] ?? null)) {
                $byPath[$file['path']] = $file;
            }
        }
        ksort($byPath, SORT_STRING);
        $mapRoot = $this->sourceRoot !== null
            ? rtrim($this->sourceRoot, '/')
            : (is_string($data['root'] ?? null) ? rtrim($data['root'], '/') : '');

        $facts = [];
        foreach ($task->files as $path) {
            $entry = $byPath[$path] ?? null;
            if ($entry === null) {
                $facts[] = new RecallFact('map.missing.' . $path, 'navigation_status', 'derived_navigation', $this->indexPath, [$path], ['status' => 'missing']);
                continue;
            }
            if ($this->isStale($mapRoot, $entry)) {
                $facts[] = new RecallFact('map.stale.' . $path, 'navigation_status', 'derived_navigation', $this->indexPath, [$path], ['status' => 'stale']);
                continue;
            }

            $payload = [
                'path' => $path,
                'namespace' => is_string($entry['namespace'] ?? null) ? $entry['namespace'] : '',
                'symbols' => $this->symbols($entry['symbols'] ?? []),
            ];
            $facts[] = new RecallFact('map.file.' . $path, 'navigation', 'derived_navigation', $this->indexPath, [$path], $payload);
        }

        return new RecallProviderResult(
            hash_file('sha256', $this->indexPath) ?: CanonicalJson::digest(['index' => $json]),
            $facts,
        );
    }

    /**
     * @param mixed $symbols
     * @return list<array{fqn: string, kind: string, line_start: int, line_end: int}>
     */
    private function symbols(mixed $symbols): array
    {
        if (!is_array($symbols)) {
            return [];
        }
        $result = [];
        foreach ($symbols as $symbol) {
            if (!is_array($symbol) || !is_string($symbol['fqn'] ?? null)) {
                continue;
            }
            $result[] = [
                'fqn' => $symbol['fqn'],
                'kind' => is_string($symbol['kind'] ?? null) ? $symbol['kind'] : 'unknown',
                'line_start' => is_int($symbol['line_start'] ?? null) ? $symbol['line_start'] : 0,
                'line_end' => is_int($symbol['line_end'] ?? null) ? $symbol['line_end'] : 0,
            ];
        }
        usort($result, static fn (array $left, array $right): int => $left['fqn'] <=> $right['fqn']);

        return $result;
    }

    /** @param array<string, mixed> $entry */
    private function isStale(string $mapRoot, array $entry): bool
    {
        $path = $entry['path'] ?? null;
        $indexedHash = $entry['sha1'] ?? null;
        if ($mapRoot === '' || !is_string($path) || !is_string($indexedHash) || $indexedHash === '') {
            return false;
        }
        $sourcePath = $mapRoot . '/' . $path;
        if (!is_file($sourcePath)) {
            return true;
        }

        return sha1_file($sourcePath) !== $indexedHash;
    }
}
