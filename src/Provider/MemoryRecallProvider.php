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

        $scopedMemory = $this->pruneMemoryForTask($memory, $task);

        $payload = ['content' => $scopedMemory];
        if ($loaded['sourceSha256'] !== null) {
            $payload['canonical_source_ref'] = 'MEMORY.md';
            $payload['source_sha256'] = $loaded['sourceSha256'];
        }

        return new RecallProviderResult(
            CanonicalJson::digest($payload),
            [new RecallFact('memory.global', 'memory', 'repository_memory', 'MEMORY.md', ['/'], $payload)],
        );
    }

    private function pruneMemoryForTask(string $memory, TaskBrief $task): string
    {
        if (strlen($memory) <= 12000) {
            return $memory;
        }

        if ($task->files === [] && $task->scopes === [] && $task->tags === []) {
            return $memory;
        }

        $sections = preg_split('/(?=\n##\s+)/', $memory);
        if ($sections === false || count($sections) <= 1) {
            return $memory;
        }

        $requiredKeywords = [
            'core', 'general', 'global', 'north star', 'architecture', 'principles',
            'security', 'conventions', 'baseline', 'rules of engagement', 'never',
            'invariant', 'safety', 'workflow', 'standards',
        ];

        $taskTokens = [];
        foreach ($task->tags as $tag) {
            $taskTokens[strtolower(trim($tag))] = true;
        }
        foreach (array_merge($task->files, $task->scopes) as $path) {
            $segments = preg_split('~[/\\\\]+~', strtolower($path)) ?: [];
            foreach ($segments as $seg) {
                $base = pathinfo($seg, PATHINFO_FILENAME);
                if (strlen($base) >= 3) {
                    $taskTokens[$base] = true;
                }
            }
        }
        if ($task->description !== '') {
            $words = preg_split('/\W+/', strtolower($task->description)) ?: [];
            foreach ($words as $word) {
                if (strlen($word) >= 4) {
                    $taskTokens[$word] = true;
                }
            }
        }

        $retained = [];
        $preamble = array_shift($sections);
        if (trim($preamble) !== '') {
            $retained[] = trim($preamble);
        }

        foreach ($sections as $section) {
            $trimmed = trim($section);
            if ($trimmed === '') {
                continue;
            }

            $firstLine = strtok($trimmed, "\r\n");
            $headingLower = strtolower($firstLine);

            $isRequired = false;
            foreach ($requiredKeywords as $kw) {
                if (str_contains($headingLower, $kw)) {
                    $isRequired = true;
                    break;
                }
            }
            if ($isRequired) {
                $retained[] = $trimmed;
                continue;
            }

            $isRelevant = false;
            foreach (array_keys($taskTokens) as $token) {
                if (str_contains($headingLower, $token)) {
                    $isRelevant = true;
                    break;
                }
            }
            if ($isRelevant) {
                $retained[] = $trimmed;
            }
        }

        if (count($retained) <= 1 && count($sections) > 1) {
            return $memory;
        }

        return implode("\n\n", $retained);
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
        $realProject = realpath($rootConfig->projectRoot);
        $realPath = realpath($path);
        if (
            is_link($path)
            || !is_file($path)
            || $realProject === false
            || $realPath === false
            || !str_starts_with($realPath, rtrim($realProject, '/\\') . DIRECTORY_SEPARATOR)
        ) {
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
