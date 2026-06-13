<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler;

use RuntimeException;

final class RecallRepository
{
    /**
     * Load MEMORY.md content. Returns empty string if not found.
     */
    public function loadMemory(string $root): string
    {
        $path = $root . '/MEMORY.md';
        if (!is_file($path)) {
            // Also check parent directory if $root is infra/doc/agent-learning
            $parentPath = dirname($root) . '/MEMORY.md';
            if (is_file($parentPath)) {
                $path = $parentPath;
            } else {
                $grandParentPath = dirname(dirname($root)) . '/MEMORY.md';
                if (is_file($grandParentPath)) {
                    $path = $grandParentPath;
                } else {
                    return '';
                }
            }
        }

        $content = file_get_contents($path);
        return $content === false ? '' : $content;
    }

    /**
     * @return list<RecallGuidance>
     */
    public function loadActiveGuidance(string $root): array
    {
        $guidance = [];
        $dirs = ['approved', 'applied'];
        foreach ($dirs as $dir) {
            $dirPath = $root . '/proposals/' . $dir;
            if (!is_dir($dirPath)) {
                continue;
            }
            $files = glob($dirPath . '/*.json');
            if ($files === false) {
                continue;
            }
            foreach ($files as $file) {
                $item = $this->parseGuidanceFile($file, $dir);
                if ($item !== null) {
                    $guidance[] = $item;
                }
            }
        }
        return $guidance;
    }

    /**
     * @return list<RecallRejection>
     */
    public function loadRejectedGuidance(string $root): array
    {
        $rejections = [];
        $historyPath = $root . '/history/rejected-proposals.jsonl';
        if (!is_file($historyPath)) {
            return [];
        }

        $lines = file($historyPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }

        foreach ($lines as $line) {
            try {
                $record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }

            if (!is_array($record)) {
                continue;
            }

            $proposalId = $record['proposal_id'] ?? null;
            $reason = $record['reason'] ?? '';

            if (!is_string($proposalId) || $proposalId === '') {
                continue;
            }

            // Look up scope and details from proposals/rejected/<proposalId>.json
            $proposalFile = $root . '/proposals/rejected/' . $proposalId . '.json';
            $scope = [];
            $action = 'unknown';
            $target = null;

            if (is_file($proposalFile)) {
                $propContent = file_get_contents($proposalFile);
                if ($propContent !== false) {
                    try {
                        $propData = json_decode($propContent, true, 512, JSON_THROW_ON_ERROR);
                        if (is_array($propData)) {
                            $scope = $propData['scope'] ?? [];
                            $action = $propData['action'] ?? 'unknown';
                            $target = $propData['target'] ?? null;
                        }
                    } catch (\JsonException) {
                        // ignore
                    }
                }
            }

            $rejections[] = new RecallRejection(
                $proposalId,
                $reason,
                is_array($scope) ? array_values(array_filter($scope, 'is_string')) : [],
                $action,
                $target
            );
        }

        return $rejections;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function loadOutcomes(string $root): array
    {
        $outcomesPath = $root . '/history/outcomes.jsonl';
        if (!is_file($outcomesPath)) {
            return [];
        }

        $lines = file($outcomesPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }

        $outcomes = [];
        foreach ($lines as $line) {
            try {
                $record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($record)) {
                    $outcomes[] = $record;
                }
            } catch (\JsonException) {
                // ignore
            }
        }
        return $outcomes;
    }

    private function parseGuidanceFile(string $file, string $status): ?RecallGuidance
    {
        $content = file_get_contents($file);
        if ($content === false) {
            return null;
        }
        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($data)) {
            return null;
        }

        $id = $data['id'] ?? pathinfo($file, PATHINFO_FILENAME);
        $action = $data['action'] ?? '';
        $targetType = $data['target_type'] ?? null;
        $target = $data['target'] ?? null;
        $scope = $data['scope'] ?? [];
        $old = $data['old'] ?? null;
        $new = $data['new'] ?? null;
        $reason = $data['reason'] ?? '';
        $boundary = $data['boundary'] ?? null;
        $validation = $data['validation'] ?? [];

        return new RecallGuidance(
            $id,
            $action,
            $targetType,
            $target,
            is_array($scope) ? array_values(array_filter($scope, 'is_string')) : [],
            $old,
            $new,
            $reason,
            $boundary,
            is_array($validation) ? array_values(array_filter($validation, 'is_string')) : [],
            $status
        );
    }
}
