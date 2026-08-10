<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler;

use RuntimeException;

final class TaskBriefParser
{
    public function parseFile(string $path): TaskBrief
    {
        $data = $this->decodeFile($path);
        if (($data['kind'] ?? null) === 'governed_recall_input') {
            return $this->parseGovernedInput($data, $path);
        }

        return $this->parseTaskData($data, $path);
    }

    /** @return array<string, mixed> */
    private function decodeFile(string $path): array
    {
        if (!is_file($path)) {
            throw new RuntimeException('task brief file not found: ' . $path);
        }
        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException('cannot read task brief file: ' . $path);
        }
        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RuntimeException('malformed JSON in task brief: ' . $e->getMessage());
        }
        if (!is_array($data)) {
            throw new RuntimeException('task brief must be a JSON object');
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /** @param array<string, mixed> $data */
    private function parseGovernedInput(array $data, string $envelopePath): TaskBrief
    {
        if (($data['schema_version'] ?? null) !== '1.0') {
            throw new RuntimeException('unsupported governed recall input schema version');
        }
        $runId = $data['run_id'] ?? null;
        if (!is_string($runId) || trim($runId) === '') {
            throw new RuntimeException('governed recall input requires non-empty run_id');
        }
        $contract = $data['contract'] ?? null;
        if (!is_array($contract)) {
            throw new RuntimeException('governed recall input requires contract object');
        }
        $sourceRef = $contract['path'] ?? null;
        $sha256 = $contract['sha256'] ?? null;
        $revision = $contract['revision'] ?? null;
        if (!is_string($sourceRef) || trim($sourceRef) === '') {
            throw new RuntimeException('governed recall input contract.path must be non-empty');
        }
        if (!is_string($sha256) || preg_match('/^sha256:[a-f0-9]{64}$/', $sha256) !== 1) {
            throw new RuntimeException('governed recall input contract.sha256 is invalid');
        }
        if (!is_int($revision) || $revision < 1) {
            throw new RuntimeException('governed recall input contract.revision must be positive');
        }

        $contractPath = $this->resolveReference(dirname($envelopePath), $sourceRef);
        $actualHash = hash_file('sha256', $contractPath);
        if ($actualHash === false || !hash_equals($sha256, 'sha256:' . $actualHash)) {
            throw new RuntimeException('governed recall input Contract digest does not match current source');
        }

        $binding = new GovernedRunBinding(trim($runId), $revision, trim($sourceRef), $sha256);
        $task = $this->parseTaskData($this->decodeFile($contractPath), $contractPath, $binding);
        if ($task->status !== 'approved') {
            throw new RuntimeException('governed recall input requires an approved Contract');
        }
        if ($task->revision !== $revision) {
            throw new RuntimeException('governed recall input Contract revision does not match current source');
        }

        return $task;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function parseTaskData(array $data, string $path, ?GovernedRunBinding $governedRun = null): TaskBrief
    {
        if (isset($data['schema_version']) && $data['schema_version'] !== '1.0') {
            throw new RuntimeException('unsupported task brief schema version: ' . $data['schema_version']);
        }

        $id = $data['id'] ?? $data['task_id'] ?? '';
        if (!is_string($id) || trim($id) === '') {
            throw new RuntimeException('missing or empty task ID in brief');
        }

        $description = $data['description'] ?? $data['goal'] ?? '';
        if (!is_string($description)) {
            throw new RuntimeException('task description must be a string');
        }

        $files = $data['files'] ?? $data['scope'] ?? [];
        if (!is_array($files)) {
            throw new RuntimeException('task files must be an array');
        }

        $scopes = $data['scopes'] ?? $data['scope'] ?? [];
        if (!is_array($scopes)) {
            throw new RuntimeException('task scopes must be an array');
        }

        $fileList = $this->stringList($files);
        $scopeList = $this->stringList($scopes);
        $nonGoals = $data['non_goals'] ?? [];
        $validation = $data['validation'] ?? [];
        if (!is_array($nonGoals) || !is_array($validation)) {
            throw new RuntimeException('task non_goals and validation must be arrays');
        }

        $status = $data['status'] ?? null;
        if ($status !== null && !is_string($status)) {
            throw new RuntimeException('task status must be a string');
        }
        $revision = $data['revision'] ?? null;
        if ($revision !== null && (!is_int($revision) || $revision < 1)) {
            throw new RuntimeException('task revision must be a positive integer');
        }

        $tags = $data['tags'] ?? [];
        if (!is_array($tags)) {
            throw new RuntimeException('task tags must be an array');
        }

        $behaviorAnchors = $data['behavior_anchors'] ?? [];
        if (!is_array($behaviorAnchors)) {
            throw new RuntimeException('task behavior_anchors must be an array');
        }

        $targets = [];
        if (array_key_exists('targets', $data)) {
            if (!is_array($data['targets'])) {
                throw new RuntimeException('task targets must be an array');
            }
            $targets = $this->targetList($data['targets']);
        }

        $operatingPrompts = [];
        if (array_key_exists('operating_prompts', $data)) {
            if (!is_array($data['operating_prompts'])) {
                throw new RuntimeException('task operating_prompts must be an array');
            }
            $operatingPrompts = $this->operatingPromptList($data['operating_prompts']);
        }

        return new TaskBrief(
            trim($id),
            $description,
            $fileList,
            $scopeList,
            $this->stringList($nonGoals),
            $this->stringList($validation),
            $status === null ? null : trim($status),
            $revision,
            $path,
            $this->stringList($tags),
            $this->stringList($behaviorAnchors),
            $targets,
            $operatingPrompts,
            $governedRun,
        );
    }

    private function resolveReference(string $baseDirectory, string $reference): string
    {
        $candidate = str_starts_with($reference, '/') ? $reference : $baseDirectory . '/' . $reference;
        $resolved = realpath($candidate);
        if ($resolved === false || !is_file($resolved)) {
            throw new RuntimeException('governed recall Contract source not found: ' . $reference);
        }

        return $resolved;
    }

    /**
     * @param array<mixed> $values
     * @return list<string>
     */
    private function stringList(array $values): array
    {
        $list = [];
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                $list[] = trim($value);
            }
        }

        return array_values(array_unique($list));
    }

    /**
     * @param array<mixed> $values
     * @return list<string>
     */
    private function targetList(array $values): array
    {
        $targets = [];
        foreach ($values as $value) {
            if (!is_string($value) || trim($value) === '') {
                throw new RuntimeException('task targets must contain only non-empty strings');
            }
            $targets[] = trim($value);
        }

        return array_values(array_unique($targets));
    }

    /**
     * @param array<mixed> $values
     * @return list<OperatingPromptRequest>
     */
    private function operatingPromptList(array $values): array
    {
        $requests = [];
        $seenIds = [];
        foreach ($values as $value) {
            if (!is_array($value)) {
                throw new RuntimeException('task operating_prompts entries must be JSON objects');
            }
            try {
                /** @var array<string, mixed> $value */
                $request = OperatingPromptRequest::fromArray($value);
            } catch (\InvalidArgumentException $exception) {
                throw new RuntimeException('invalid task operating prompt: ' . $exception->getMessage(), 0, $exception);
            }
            if (isset($seenIds[$request->id])) {
                throw new RuntimeException('task selects operating prompt more than once: ' . $request->id);
            }
            $seenIds[$request->id] = true;
            $requests[] = $request;
        }

        return $requests;
    }
}
