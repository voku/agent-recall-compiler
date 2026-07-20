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
                $grandParentPath = dirname($root, 2) . '/MEMORY.md';
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
     * Retired proposals (voku/agent-learning ProposalStatus::RETIRED) are never selectable as
     * active guidance, but historical outcome events legitimately reference them by ID, so the
     * decision engine still needs to know they exist to avoid blocking on an "unknown rule ID".
     *
     * @return list<string>
     */
    public function loadRetiredProposalIds(string $root): array
    {
        $ids = [];
        foreach ($this->loadRetiredProposals($root) as $retirement) {
            $ids[] = $retirement->id;
        }

        return $ids;
    }

    /**
     * Full retired-proposal records (target + retirement reason), so the decision engine can
     * check whether newly selected guidance contradicts a rule that was deliberately retired,
     * not just whether the ID is "known" for outcome-log purposes.
     *
     * @return list<RecallRetirement>
     */
    public function loadRetiredProposals(string $root): array
    {
        $dirPath = $root . '/proposals/retired';
        if (!is_dir($dirPath)) {
            return [];
        }

        $files = glob($dirPath . '/*.json');
        if ($files === false) {
            return [];
        }

        $retirements = [];
        foreach ($files as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }
            try {
                $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }
            if (!is_array($data)) {
                continue;
            }

            $id = $data['id'] ?? null;
            if (!is_string($id) || $id === '') {
                continue;
            }

            $reason = $data['reason'] ?? '';
            $scope = $data['scope'] ?? [];
            $action = $data['action'] ?? 'unknown';
            $target = $data['target'] ?? null;

            $retirements[] = new RecallRetirement(
                $id,
                is_string($reason) ? $reason : '',
                is_array($scope) ? array_values(array_filter($scope, 'is_string')) : [],
                is_string($action) ? $action : 'unknown',
                is_string($target) ? $target : null
            );
        }

        return $retirements;
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
                    if (isset($record['schema_version']) && $record['schema_version'] !== '1.0') {
                        throw new RuntimeException("unsupported outcomes schema version: " . $record['schema_version']);
                    }
                    $outcomes[] = $record;
                }
            } catch (\JsonException) {
                // ignore
            }
        }
        return $outcomes;
    }

    /**
     * @return list<ConstraintManifest>
     */
    public function loadConstraintManifests(string $root): array
    {
        $files = [];
        foreach ($this->activeConstraintDirectories($root) as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $matches = glob($dir . '/*.json');
            if ($matches !== false) {
                array_push($files, ...$matches);
            }
        }

        $manifests = [];
        foreach (array_values(array_unique($files)) as $file) {
            $manifests[] = $this->parseConstraintManifest($file);
        }

        return $manifests;
    }

    /**
     * @return list<string>
     */
    private function activeConstraintDirectories(string $root): array
    {
        $configuredDir = $this->configuredString($root, 'active_constraints_dir');
        $dirs = [];
        if ($configuredDir !== null) {
            $dirs[] = $this->resolvePath($root, $configuredDir);
        }
        $dirs[] = $root . '/constraints/active';
        $dirs[] = $root . '/constraints';

        return array_values(array_unique($dirs));
    }

    private function configuredString(string $root, string $key): ?string
    {
        $configPath = $root . '/config.json';
        if (!is_file($configPath)) {
            return null;
        }

        $content = file_get_contents($configPath);
        if ($content === false) {
            throw new RuntimeException('cannot read path configuration: ' . $configPath);
        }

        try {
            $config = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('malformed path configuration JSON: ' . $exception->getMessage());
        }

        if (!is_array($config)) {
            throw new RuntimeException('path configuration must be a JSON object: ' . $configPath);
        }

        $value = $config[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || trim($value) === '') {
            throw new RuntimeException('invalid path configuration field: ' . $key);
        }

        return $value;
    }

    private function resolvePath(string $root, string $path): string
    {
        $path = trim($path);
        if (
            str_starts_with($path, '/')
            ||
            preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1
        ) {
            return rtrim(str_replace('\\', '/', $path), '/');
        }

        return rtrim(str_replace('\\', '/', $root . '/' . $path), '/');
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

        if (isset($data['schema_version']) && $data['schema_version'] !== '1.0') {
            throw new RuntimeException("unsupported guidance schema version in file " . $file . ": " . $data['schema_version']);
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

    private function parseConstraintManifest(string $file): ConstraintManifest
    {
        $content = file_get_contents($file);
        if ($content === false) {
            throw new RuntimeException('cannot read constraint manifest: ' . $file);
        }

        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RuntimeException('malformed constraint manifest JSON in ' . $file . ': ' . $e->getMessage());
        }

        if (!is_array($data)) {
            throw new RuntimeException('constraint manifest must be a JSON object: ' . $file);
        }

        if (($data['schema_version'] ?? null) !== '1.0') {
            throw new RuntimeException('unsupported constraint manifest schema version in ' . $file);
        }

        $engine = $this->requiredString($data, 'engine', $file);
        if (!in_array($engine, ['phpstan', 'php_cs_fixer', 'test', 'ci'], true)) {
            throw new RuntimeException('constraint references an unknown engine: ' . $engine);
        }

        $scope = $this->requiredStringList($data, 'scope', $file);
        $commands = $this->requiredStringList($data, 'validation_commands', $file);
        $ruleIdentifier = $this->requiredString($data, 'rule_identifier', $file);

        $this->assertCommandMatchesEngine($engine, $commands, $file);

        return new ConstraintManifest(
            $this->requiredString($data, 'id', $file),
            $engine,
            $ruleIdentifier,
            $scope,
            $commands,
            $this->requiredString($data, 'source_proposal', $file),
            $this->requiredString($data, 'status', $file),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function requiredString(array $data, string $key, string $file): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new RuntimeException(sprintf('constraint manifest %s requires non-empty string: %s', $file, $key));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     * @return list<string>
     */
    private function requiredStringList(array $data, string $key, string $file): array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value) || $value === []) {
            throw new RuntimeException(sprintf('constraint manifest %s requires non-empty list: %s', $file, $key));
        }

        $strings = [];
        foreach ($value as $item) {
            if (!is_string($item) || trim($item) === '') {
                throw new RuntimeException(sprintf('constraint manifest %s list %s must contain only non-empty strings', $file, $key));
            }
            $strings[] = $item;
        }

        return $strings;
    }

    /**
     * @param list<string> $commands
     */
    private function assertCommandMatchesEngine(string $engine, array $commands, string $file): void
    {
        $needle = match ($engine) {
            'phpstan' => 'phpstan',
            'php_cs_fixer' => 'php-cs-fixer',
            'test' => '',
            'ci' => '',
            default => '',
        };
        if ($needle === '') {
            return;
        }

        foreach ($commands as $command) {
            if (str_contains($command, $needle)) {
                return;
            }
        }

        throw new RuntimeException(sprintf('constraint manifest %s validation command contradicts selected engine %s', $file, $engine));
    }
}
