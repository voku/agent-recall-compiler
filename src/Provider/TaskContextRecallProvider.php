<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Provider;

use voku\AgentRecallCompiler\CanonicalJson;
use voku\AgentRecallCompiler\RecallRootConfig;
use voku\AgentRecallCompiler\TaskBrief;

/** Converts the sealed task brief into structured facts for every renderer. */
final class TaskContextRecallProvider implements RecallProvider
{
    public function manifest(): RecallProviderManifest
    {
        return new RecallProviderManifest('task-context', '1.0', []);
    }

    public function collect(TaskBrief $task, RecallRootConfig $rootConfig): RecallProviderResult
    {
        $payload = [
            'task_id' => $task->id,
            'goal' => $task->description,
            'files' => $task->files,
            'scope' => $task->scopes,
            'non_goals' => $task->nonGoals,
            'validation' => $task->validation,
            'status' => $task->status,
            'revision' => $task->revision,
            'source_path' => $task->sourcePath,
        ];

        return new RecallProviderResult(
            CanonicalJson::digest($payload),
            [new RecallFact(
                'task.' . $task->id,
                'task_context',
                $task->status === 'approved' ? 'approved_session_brief' : 'task_input',
                $task->sourcePath ?? 'inline',
                $task->scopes === [] ? $task->files : $task->scopes,
                $payload,
                'task:' . $task->id,
            )],
        );
    }
}
