<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Provider;

use RuntimeException;

/**
 * Optional adapter over voku/agent-learning's public LearningNote projection.
 *
 * Recall deliberately does not require agent-learning as a standalone package
 * dependency. Hosts that already install the Learning owner gain precedent
 * context; standalone Recall remains usable without it. Once the owner class is
 * present, owner failures are allowed to propagate rather than being rewritten
 * as an empty catalog.
 */
final readonly class AgentLearningNoteProjectionSource implements LearningNoteProjectionSource
{
    private const string DEFAULT_SERVICE_CLASS = 'voku\\AgentLearning\\LearningNoteService';

    /** @param string $serviceClass */
    public function __construct(private string $serviceClass = self::DEFAULT_SERVICE_CLASS)
    {
    }

    public function isAvailable(): bool
    {
        return class_exists($this->serviceClass);
    }

    public function active(string $learningRoot, ?string $projectRoot = null): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        $serviceClass = $this->serviceClass;
        $service = new $serviceClass();
        if (!is_callable([$service, 'activeProjections'])) {
            throw new RuntimeException('Installed Learning owner does not expose LearningNoteService::activeProjections().');
        }

        $raw = $service->activeProjections($learningRoot, $projectRoot);
        if (!is_array($raw)) {
            throw new RuntimeException('LearningNoteService::activeProjections() must return a list.');
        }

        $result = [];
        foreach ($raw as $projection) {
            if (!is_object($projection) || !is_callable([$projection, 'toArray'])) {
                throw new RuntimeException('Learning owner returned an unsupported LearningNote projection.');
            }
            $data = $projection->toArray();
            if (!is_array($data)) {
                throw new RuntimeException('LearningNote projection toArray() must return an array.');
            }
            /** @var array<string, mixed> $data */
            $result[] = $this->fromArray($data);
        }

        usort($result, static fn (LearningNotePrecedentProjection $left, LearningNotePrecedentProjection $right): int => $left->id <=> $right->id);

        return $result;
    }

    /** @param array<string, mixed> $data */
    private function fromArray(array $data): LearningNotePrecedentProjection
    {
        $status = $this->string($data, 'status');
        if ($status !== 'active') {
            throw new RuntimeException('LearningNote owner projection returned non-active status: ' . $status);
        }
        $content = $data['content'] ?? null;
        if (!is_array($content)) {
            throw new RuntimeException('LearningNote owner projection requires structured content.');
        }
        /** @var array<string, mixed> $content */

        return new LearningNotePrecedentProjection(
            id: $this->string($data, 'id'),
            patternKey: $this->string($data, 'pattern_key'),
            scope: $this->strings($data['scope'] ?? null, 'scope'),
            tags: $this->strings($data['tags'] ?? null, 'tags'),
            sourceFindings: $this->strings($data['source_findings'] ?? null, 'source_findings'),
            sourceProposals: $this->strings($data['source_proposals'] ?? [], 'source_proposals'),
            content: $content,
            digest: $this->sha256($data, 'digest'),
            evidenceState: $this->string($data, 'evidence_state'),
        );
    }

    /** @param array<string, mixed> $data */
    private function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new RuntimeException('LearningNote owner projection requires non-empty ' . $key . '.');
        }

        return trim($value);
    }

    /** @param array<string, mixed> $data */
    private function sha256(array $data, string $key): string
    {
        $value = strtolower($this->string($data, $key));
        if (preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new RuntimeException('LearningNote owner projection requires canonical SHA-256 ' . $key . '.');
        }

        return $value;
    }

    /** @return list<string> */
    private function strings(mixed $value, string $key): array
    {
        if (!is_array($value)) {
            throw new RuntimeException('LearningNote owner projection requires array ' . $key . '.');
        }
        $result = [];
        foreach ($value as $item) {
            if (!is_string($item) || trim($item) === '') {
                throw new RuntimeException('LearningNote owner projection ' . $key . ' entries must be non-empty strings.');
            }
            $result[] = trim($item);
        }

        return array_values(array_unique($result));
    }
}
