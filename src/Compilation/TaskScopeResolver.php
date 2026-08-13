<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Compilation;

use voku\AgentRecallCompiler\TaskBrief;

final readonly class TaskScopeResolver
{
    private const DERIVED_ROLES = [
        'primary' => true,
        'contract' => true,
        'change_candidate' => true,
        'verification' => true,
    ];

    /**
     * @param list<array<string, mixed>> $facts
     */
    public function resolve(TaskBrief $task, array $facts): TaskScopeResolution
    {
        $explicitFiles = $this->uniqueStrings($task->files);
        $explicitSet = array_fill_keys($explicitFiles, true);
        $derived = [];
        $derivedFrom = [];

        foreach ($facts as $fact) {
            if (($fact['type'] ?? null) !== 'edit_context') {
                continue;
            }
            $payload = is_array($fact['payload'] ?? null) ? $fact['payload'] : [];
            $slices = is_array($payload['slices'] ?? null) ? $payload['slices'] : [];
            $contributed = false;
            foreach ($slices as $slice) {
                if (!is_array($slice)) {
                    continue;
                }
                $path = $slice['path'] ?? null;
                $roles = is_array($slice['roles'] ?? null) ? $slice['roles'] : [];
                if (!is_string($path) || trim($path) === '' || !$this->hasDerivedRole($roles)) {
                    continue;
                }
                $path = trim($path);
                if (!isset($explicitSet[$path])) {
                    $derived[$path] = true;
                }
                $contributed = true;
            }
            $factId = $fact['id'] ?? null;
            if ($contributed && is_string($factId) && $factId !== '') {
                $derivedFrom[$factId] = true;
            }
        }

        $derivedFiles = array_keys($derived);
        sort($derivedFiles, SORT_STRING);
        $effectiveFiles = [...$explicitFiles, ...$derivedFiles];
        $derivedFromIds = array_keys($derivedFrom);
        sort($derivedFromIds, SORT_STRING);

        return new TaskScopeResolution(
            effectiveTask: new TaskBrief(
                id: $task->id,
                description: $task->description,
                files: $effectiveFiles,
                scopes: $task->scopes,
                nonGoals: $task->nonGoals,
                validation: $task->validation,
                status: $task->status,
                revision: $task->revision,
                sourcePath: $task->sourcePath,
                tags: $task->tags,
                behaviorAnchors: $task->behaviorAnchors,
                targets: $task->targets,
                operatingPrompts: $task->operatingPrompts,
                governedRun: $task->governedRun,
                acceptanceCriteria: $task->acceptanceCriteria,
            ),
            explicitFiles: $explicitFiles,
            derivedFiles: $derivedFiles,
            derivedFrom: $derivedFromIds,
        );
    }

    /** @param array<mixed> $roles */
    private function hasDerivedRole(array $roles): bool
    {
        foreach ($roles as $role) {
            if (is_string($role) && isset(self::DERIVED_ROLES[$role])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function uniqueStrings(array $values): array
    {
        $result = [];
        foreach ($values as $value) {
            $value = trim($value);
            if ($value !== '' && !in_array($value, $result, true)) {
                $result[] = $value;
            }
        }

        return $result;
    }
}
