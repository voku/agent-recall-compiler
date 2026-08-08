<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Provider;

use voku\AgentRecallCompiler\CanonicalJson;
use voku\AgentRecallCompiler\OperatingPromptOutcomeHistory;
use voku\AgentRecallCompiler\RecallRootConfig;
use voku\AgentRecallCompiler\TaskBrief;

final readonly class OperatingPromptOutcomeRecallProvider implements RecallProvider
{
    public function manifest(): RecallProviderManifest
    {
        return new RecallProviderManifest('operating-prompt-outcomes', '1.0', [], required: false);
    }

    public function collect(TaskBrief $task, RecallRootConfig $rootConfig): RecallProviderResult
    {
        $history = new OperatingPromptOutcomeHistory();
        $source = rtrim($rootConfig->root, '/\\') . '/history/operating-prompt-outcomes.jsonl';
        $facts = [];

        foreach ($task->operatingPrompts as $request) {
            $facts[] = new RecallFact(
                'operating-prompt-outcome.' . $request->id,
                'operating_prompt_outcome_stats',
                'historical_outcome',
                $source,
                $task->scopes === [] ? $task->files : $task->scopes,
                [
                    'prompt_id' => $request->id,
                    'arguments_sha256' => CanonicalJson::digest($request->arguments),
                    'stats' => $history->stats($rootConfig->root, $request->id),
                ],
            );
        }

        return new RecallProviderResult(
            CanonicalJson::digest([
                'provider' => 'operating-prompt-outcomes',
                'facts' => array_map(static fn (RecallFact $fact): array => $fact->toArray(), $facts),
            ]),
            $facts,
        );
    }
}
