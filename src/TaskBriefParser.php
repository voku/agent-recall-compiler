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

        $description = $data['description'] ?? '';
        if (!is_string($description)) {
            throw new RuntimeException('task description must be a string');
        }

        $files = $data['files'] ?? [];
        if (!is_array($files)) {
            throw new RuntimeException('task files must be an array');
        }

        $scopes = $data['scopes'] ?? [];
        if (!is_array($scopes)) {
            throw new RuntimeException('task scopes must be an array');
        }

        $fileList = $this->stringList($files);
        $scopeList = $this->stringList($scopes);

        return new TaskBrief($id, $description, $fileList, $scopeList);
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
