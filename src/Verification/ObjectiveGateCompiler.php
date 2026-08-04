<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Verification;

use voku\AgentMap\Context\EditContextPlan;
use voku\AgentRecallCompiler\TaskBrief;

/** Declares objective gates. It deliberately executes none of them. */
final readonly class ObjectiveGateCompiler
{
    /** @return list<ObjectiveGate> */
    public function compile(TaskBrief $task, EditContextPlan $context): array
    {
        $gates = [
            new ObjectiveGate('gate:runner-exit', 'runner_exit', ['source' => 'edit_runner']),
            new ObjectiveGate('gate:php-lint-changed-files', 'php_lint_changed_files', ['scope' => 'changed_php_files']),
            new ObjectiveGate('gate:post-edit-map-fresh', 'post_edit_map_fresh', ['pre_edit_map_digest' => $context->mapDigest]),
            new ObjectiveGate('gate:target-resolvable', 'target_resolvable', ['target' => $context->resolvedTarget->id]),
            new ObjectiveGate('gate:agent-loop-verify', 'agent_loop_verify', ['command' => 'agent-loop verify']),
        ];

        $commands = array_values(array_unique($task->validation));
        sort($commands, SORT_STRING);
        foreach ($commands as $command) {
            $gates[] = new ObjectiveGate(
                id: 'gate:approved-validation-command:' . substr(hash('sha256', $command), 0, 12),
                kind: 'approved_validation_command',
                provenance: ['command' => $command],
            );
        }

        return $gates;
    }
}
