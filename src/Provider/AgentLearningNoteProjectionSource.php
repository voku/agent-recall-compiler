<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Provider;

use RuntimeException;

/**
 * Optional adapter over voku/agent-learning's public bounded task-precedent projection.
 *
 * Recall deliberately does not require agent-learning as a standalone package
 * dependency. Hosts that already install the Learning owner gain precedent
 * context; standalone Recall remains usable without it. Once the owner class is
 * present, owner failures are allowed to propagate rather than being rewritten
 * as an empty observation.
 */
final readonly class AgentLearningNoteProjectionSource implements LearningNoteProjectionSource
{
    private const string DEFAULT_SERVICE_CLASS = 'voku\\AgentLearning\\LearningLineageService';

    /** @param string $serviceClass */
    public function __construct(private string $serviceClass = self::DEFAULT_SERVICE_CLASS)
    {
    }

    public function isAvailable(): bool
    {
        return class_exists($this->serviceClass);
    }

    public function forTask(
        string $learningRoot,
        string $taskId,
        ?string $projectRoot = null,
    ): LearningTaskPrecedentProjection {
        if (!$this->isAvailable()) {
            throw new RuntimeException('Installed Learning owner does not expose LearningLineageService.');
        }

        $serviceClass = $this->serviceClass;
        $service = new $serviceClass();
        if (!is_callable([$service, 'precedentsForTask'])) {
            throw new RuntimeException('Installed Learning owner does not expose LearningLineageService::precedentsForTask().');
        }

        $raw = $service->precedentsForTask($learningRoot, $taskId, $projectRoot);
        if (!is_object($raw) || !is_callable([$raw, 'toArray'])) {
            throw new RuntimeException('LearningLineageService::precedentsForTask() must return a typed projection.');
        }
        $data = $raw->toArray();
        if (!is_array($data)) {
            throw new RuntimeException('Learning task-precedent projection toArray() must return an array.');
        }
        /** @var array<string, mixed> $data */

        $ownerTaskId = $this->string($data, 'task_id', 'Learning task-precedent projection');
        if ($ownerTaskId !== $taskId) {
            throw new RuntimeException('Learning task-precedent projection is bound to a different task id.');
        }

        $precedents = $data['precedents'] ?? null;
        if (!is_array($precedents)) {
            throw new RuntimeException('Learning task-precedent projection requires a precedents list.');
        }
        $notes = [];
        foreach ($precedents as $precedent) {
            if (!is_array($precedent)) {
                throw new RuntimeException('Learning task-precedent projection contains an unsupported precedent.');
            }
            /** @var array<string, mixed> $precedent */
            $notes[] = $this->fromArray($precedent);
        }

        $lineage = $data['lineage'] ?? null;
        if (!is_array($lineage)) {
            throw new RuntimeException('Learning task-precedent projection requires a lineage envelope.');
        }
        /** @var array<string, mixed> $lineage */
        $identityId = $this->string($lineage, 'identity_id', 'Learning lineage envelope');
        if ($identityId !== $taskId) {
            throw new RuntimeException('Learning lineage envelope is bound to a different task id.');
        }
        $identityDepths = $this->identityDepths($lineage);

        $precedentsTruncated = null;
        if (array_key_exists('precedents_truncated', $data)) {
            $precedentsTruncated = $this->boolean($data, 'precedents_truncated');
        }

        return new LearningTaskPrecedentProjection(
            taskId: $ownerTaskId,
            precedents: $notes,
            identityIds: $this->strings($lineage['identity_ids'] ?? null, 'identity_ids'),
            depthByIdentityId: $this->depthMap($identityDepths),
            relations: $this->relations($lineage['relations'] ?? null),
            maximumDepth: $this->positiveInteger($lineage, 'maximum_depth'),
            maximumResults: $this->positiveInteger($lineage, 'maximum_results'),
            truncated: $this->boolean($lineage, 'truncated'),
            precedentsTruncated: $precedentsTruncated,
            identityDepths: $identityDepths,
        );
    }

    /** @param array<string, mixed> $data */
    private function fromArray(array $data): LearningNotePrecedentProjection
    {
        $status = $this->string($data, 'status', 'LearningNote owner projection');
        if ($status !== 'active') {
            throw new RuntimeException('LearningNote owner projection returned non-active status: ' . $status);
        }
        $content = $data['content'] ?? null;
        if (!is_array($content)) {
            throw new RuntimeException('LearningNote owner projection requires structured content.');
        }
        /** @var array<string, mixed> $content */

        return new LearningNotePrecedentProjection(
            id: $this->string($data, 'id', 'LearningNote owner projection'),
            patternKey: $this->string($data, 'pattern_key', 'LearningNote owner projection'),
            scope: $this->strings($data['scope'] ?? null, 'scope'),
            tags: $this->strings($data['tags'] ?? null, 'tags'),
            sourceFindings: $this->strings($data['source_findings'] ?? null, 'source_findings'),
            sourceProposals: $this->strings($data['source_proposals'] ?? [], 'source_proposals'),
            content: $content,
            digest: $this->sha256($data, 'digest'),
            evidenceState: $this->string($data, 'evidence_state', 'LearningNote owner projection'),
        );
    }

    /** @param array<string, mixed> $data */
    private function string(array $data, string $key, string $owner): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new RuntimeException($owner . ' requires non-empty ' . $key . '.');
        }

        return trim($value);
    }

    /** @param array<string, mixed> $data */
    private function sha256(array $data, string $key): string
    {
        $value = strtolower($this->string($data, $key, 'LearningNote owner projection'));
        if (preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new RuntimeException('LearningNote owner projection requires canonical SHA-256 ' . $key . '.');
        }

        return $value;
    }

    /** @return list<string> */
    private function strings(mixed $value, string $key): array
    {
        if (!is_array($value)) {
            throw new RuntimeException('Learning owner projection requires array ' . $key . '.');
        }
        $result = [];
        foreach ($value as $item) {
            if (!is_string($item) || trim($item) === '') {
                throw new RuntimeException('Learning owner projection ' . $key . ' entries must be non-empty strings.');
            }
            $result[] = trim($item);
        }

        return array_values(array_unique($result));
    }

    /**
     * @param array<string, mixed> $lineage
     * @return list<array{identity_id: string, depth: int}>
     */
    private function identityDepths(array $lineage): array
    {
        if (array_key_exists('identity_depths', $lineage)) {
            return $this->losslessDepths($lineage['identity_depths']);
        }

        return $this->legacyDepths($lineage['depth_by_identity_id'] ?? null);
    }

    /** @return list<array{identity_id: string, depth: int}> */
    private function losslessDepths(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new RuntimeException('Learning lineage envelope requires identity_depths list.');
        }

        $result = [];
        $seen = [];
        foreach ($value as $entry) {
            if (!is_array($entry)) {
                throw new RuntimeException('Learning lineage envelope contains an invalid identity depth.');
            }
            /** @var array<string, mixed> $entry */
            $identityId = $this->string($entry, 'identity_id', 'Learning lineage identity depth');
            $depth = $entry['depth'] ?? null;
            if (!is_int($depth) || $depth < 0) {
                throw new RuntimeException('Learning lineage envelope contains an invalid identity depth.');
            }
            if (in_array($identityId, $seen, true)) {
                throw new RuntimeException('Learning lineage envelope contains a duplicate identity depth.');
            }
            $seen[] = $identityId;
            $result[] = [
                'identity_id' => $identityId,
                'depth' => $depth,
            ];
        }

        return $result;
    }

    /** @return list<array{identity_id: string, depth: int}> */
    private function legacyDepths(mixed $value): array
    {
        if (!is_array($value)) {
            throw new RuntimeException('Learning lineage envelope requires depth_by_identity_id.');
        }

        $result = [];
        $seen = [];
        foreach ($value as $identityId => $depth) {
            $identityId = trim((string) $identityId);
            if ($identityId === '' || !is_int($depth) || $depth < 0) {
                throw new RuntimeException('Learning lineage envelope contains an invalid identity depth.');
            }
            if (in_array($identityId, $seen, true)) {
                throw new RuntimeException('Learning lineage envelope contains a duplicate identity depth.');
            }
            $seen[] = $identityId;
            $result[] = [
                'identity_id' => $identityId,
                'depth' => $depth,
            ];
        }

        return $result;
    }

    /**
     * @param list<array{identity_id: string, depth: int}> $identityDepths
     * @return array<int|string, int>
     */
    private function depthMap(array $identityDepths): array
    {
        $result = [];
        foreach ($identityDepths as $entry) {
            $result[$entry['identity_id']] = $entry['depth'];
        }

        return $result;
    }

    /** @return list<array{source_id: string, kind: string, target_id: string}> */
    private function relations(mixed $value): array
    {
        if (!is_array($value)) {
            throw new RuntimeException('Learning lineage envelope requires a relations list.');
        }
        $result = [];
        foreach ($value as $relation) {
            if (!is_array($relation)) {
                throw new RuntimeException('Learning lineage envelope contains an invalid relation.');
            }
            /** @var array<string, mixed> $relation */
            $result[] = [
                'source_id' => $this->string($relation, 'source_id', 'Learning lineage relation'),
                'kind' => $this->string($relation, 'kind', 'Learning lineage relation'),
                'target_id' => $this->string($relation, 'target_id', 'Learning lineage relation'),
            ];
        }

        return $result;
    }

    /** @param array<string, mixed> $data */
    private function positiveInteger(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        if (!is_int($value) || $value < 1) {
            throw new RuntimeException('Learning lineage envelope requires positive integer ' . $key . '.');
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private function boolean(array $data, string $key): bool
    {
        $value = $data[$key] ?? null;
        if (!is_bool($value)) {
            throw new RuntimeException('Learning owner projection requires boolean ' . $key . '.');
        }

        return $value;
    }
}
