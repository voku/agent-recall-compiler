<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler;

use RuntimeException;

final class TaskBriefParser
{
    public function parseFile(string $path): TaskBrief
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

        if (isset($data['schema_version']) && $data['schema_version'] !== '1.0') {
            throw new RuntimeException("unsupported task brief schema version: " . $data['schema_version']);
        }

        $id = $data['id'] ?? $data['task_id'] ?? '';
        if (!is_string($id) || trim($id) === '') {
            throw new RuntimeException('missing or empty task ID in brief');
        }

        // agent-session's approved work-brief is a first-class task-context
        // input: its goal and scope are more precise than hand-entered --file
        // values, while the legacy task-brief shape keeps working unchanged.
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

        return new TaskBrief(
            $id,
            $description,
            $fileList,
            $scopeList,
            $this->stringList($nonGoals),
            $this->stringList($validation),
            $status === null ? null : trim($status),
            $revision,
            $path,
        );
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
}
