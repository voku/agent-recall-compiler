<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Provider;

use RuntimeException;
use voku\AgentRecallCompiler\CanonicalJson;
use voku\AgentRecallCompiler\RecallRootConfig;
use voku\AgentRecallCompiler\TaskBrief;

/**
 * Reads explicitly registered, Git-tracked project skills and ADRs. This is a
 * deliberately narrow adapter: discovery is a project policy decision, while
 * scope matching and excerpt limits are deterministic and replayable here.
 */
final class ScopedDocumentRecallProvider implements RecallProvider
{
    public function __construct(private readonly string $manifestPath)
    {
    }

    public function manifest(): RecallProviderManifest
    {
        if (!is_file($this->manifestPath)) {
            throw new RuntimeException('document manifest not found: ' . $this->manifestPath);
        }
        $digest = hash_file('sha256', $this->manifestPath);
        if (!is_string($digest)) {
            throw new RuntimeException('cannot hash document manifest: ' . $this->manifestPath);
        }

        return new RecallProviderManifest('project-documents.' . substr($digest, 0, 16), '1.0', [$this->manifestPath], required: false);
    }

    public function collect(TaskBrief $task, RecallRootConfig $rootConfig): RecallProviderResult
    {
        $manifest = $this->manifest();
        $data = $this->decodeManifest();
        $documents = $data['documents'] ?? null;
        if (!is_array($documents)) {
            throw new RuntimeException('document manifest requires a documents array: ' . $this->manifestPath);
        }

        $facts = [];
        $seenIds = [];
        foreach ($documents as $document) {
            if (!is_array($document)) {
                throw new RuntimeException('document manifest entries must be JSON objects: ' . $this->manifestPath);
            }
            $id = $this->nonEmptyString($document, 'id');
            if (isset($seenIds[$id])) {
                throw new RuntimeException('document manifest has duplicate document ID: ' . $id);
            }
            $seenIds[$id] = true;

            $type = $this->nonEmptyString($document, 'type');
            if (!in_array($type, ['adr', 'skill'], true)) {
                throw new RuntimeException('document ' . $id . ' has unsupported type: ' . $type);
            }
            $scope = $this->stringList($document['scope'] ?? []);
            $tags = $this->stringList($document['tags'] ?? []);
            if (!$this->matchesTask($scope, $tags, $task)) {
                continue;
            }

            $source = $this->nonEmptyString($document, 'source');
            if (str_starts_with($source, '/') || preg_match('~^[A-Za-z]:[\\\\/]~', $source) === 1) {
                throw new RuntimeException('document ' . $id . ' source must be relative to its manifest');
            }
            $sourcePath = dirname($this->manifestPath) . '/' . $source;
            if (!is_file($sourcePath)) {
                throw new RuntimeException('document source not found for ' . $id . ': ' . $source);
            }
            $content = file_get_contents($sourcePath);
            if ($content === false) {
                throw new RuntimeException('cannot read document source for ' . $id . ': ' . $source);
            }
            $maxChars = $document['max_chars'] ?? 4000;
            if (!is_int($maxChars) || $maxChars < 1 || $maxChars > 12000) {
                throw new RuntimeException('document ' . $id . ' max_chars must be an integer between 1 and 12000');
            }
            $normalized = rtrim(str_replace(["\r\n", "\r"], "\n", $content));
            $excerpt = $this->truncate($normalized, $maxChars);
            $authority = $document['authority'] ?? ($type === 'adr' ? 'project_adr' : 'project_skill');
            if (!is_string($authority) || trim($authority) === '') {
                throw new RuntimeException('document ' . $id . ' authority must be a non-empty string');
            }
            $priority = $document['priority'] ?? 0;
            if (!is_int($priority)) {
                throw new RuntimeException('document ' . $id . ' priority must be an integer');
            }
            $conflictKey = $document['conflict_key'] ?? null;
            if ($conflictKey !== null && (!is_string($conflictKey) || trim($conflictKey) === '')) {
                throw new RuntimeException('document ' . $id . ' conflict_key must be a non-empty string when set');
            }

            $facts[] = new RecallFact(
                'document.' . $id,
                $type,
                trim($authority),
                $source,
                $scope,
                [
                    'document_id' => $id,
                    'content' => $excerpt,
                    'content_sha256' => hash('sha256', $normalized),
                    'truncated' => $excerpt !== $normalized,
                    'tags' => $tags,
                ],
                $conflictKey,
                $priority,
            );
        }
        usort($facts, static fn (RecallFact $left, RecallFact $right): int => $left->id <=> $right->id);

        return new RecallProviderResult(
            CanonicalJson::digest([
                'manifest_sha256' => hash_file('sha256', $this->manifestPath),
                'provider' => $manifest->id,
                'facts' => array_map(static fn (RecallFact $fact): array => $fact->toArray(), $facts),
            ]),
            $facts,
        );
    }

    /** @return array<string, mixed> */
    private function decodeManifest(): array
    {
        $content = file_get_contents($this->manifestPath);
        if ($content === false) {
            throw new RuntimeException('cannot read document manifest: ' . $this->manifestPath);
        }
        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('invalid document manifest: ' . $exception->getMessage());
        }
        if (!is_array($data) || ($data['schema_version'] ?? null) !== '1.0') {
            throw new RuntimeException('document manifest must use schema_version "1.0"');
        }

        return $data;
    }

    /** @param array<string, mixed> $document */
    private function nonEmptyString(array $document, string $key): string
    {
        $value = $document[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new RuntimeException('document manifest entry requires non-empty string ' . $key);
        }

        return trim($value);
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            throw new RuntimeException('document scope must be an array');
        }
        /** @var list<string> $values */
        $values = [];
        foreach ($value as $item) {
            if (!is_string($item) || trim($item) === '') {
                throw new RuntimeException('document scope entries must be non-empty strings');
            }
            $values[] = trim($item);
        }

        return array_values(array_unique($values));
    }

    /**
     * A document matches when its path scope overlaps the task's files, when it declares no
     * scope at all (project-wide), or when it shares at least one relevance tag with the task.
     * Tags let a project register documents by domain/system/capability instead of directory
     * prefix, so this provider works the same way regardless of how a project lays out its code.
     *
     * @param list<string> $scope
     * @param list<string> $tags
     */
    private function matchesTask(array $scope, array $tags, TaskBrief $task): bool
    {
        if ($scope === [] || in_array('*', $scope, true) || in_array('/', $scope, true)) {
            return true;
        }
        foreach ($task->files as $file) {
            foreach ($scope as $candidate) {
                $prefix = rtrim($candidate, '/');
                if ($file === $prefix || str_starts_with($file, $prefix . '/')) {
                    return true;
                }
            }
        }

        return $tags !== [] && $task->tags !== [] && array_intersect($tags, $task->tags) !== [];
    }

    private function truncate(string $content, int $maxChars): string
    {
        if (mb_strlen($content, 'UTF-8') <= $maxChars) {
            return $content;
        }

        return rtrim(mb_strcut($content, 0, $maxChars, 'UTF-8')) . "\n[truncated by document manifest]";
    }
}
