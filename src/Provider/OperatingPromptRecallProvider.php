<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Provider;

use RuntimeException;
use voku\AgentRecallCompiler\CanonicalJson;
use voku\AgentRecallCompiler\OperatingPromptCatalog;
use voku\AgentRecallCompiler\OperatingPromptOutcomeHistory;
use voku\AgentRecallCompiler\RecallRootConfig;
use voku\AgentRecallCompiler\TaskBrief;

final readonly class OperatingPromptRecallProvider implements RecallProvider
{
    /** @var list<string> */
    private array $manifestPaths;

    /** @param list<string> $manifestPaths */
    public function __construct(array $manifestPaths)
    {
        $normalized = [];
        foreach ($manifestPaths as $manifestPath) {
            $manifestPath = trim($manifestPath);
            if ($manifestPath !== '' && !in_array($manifestPath, $normalized, true)) {
                $normalized[] = $manifestPath;
            }
        }
        sort($normalized, SORT_STRING);
        if ($normalized === []) {
            throw new \InvalidArgumentException('at least one operating prompt manifest is required');
        }

        $this->manifestPaths = $normalized;
    }

    public function manifest(): RecallProviderManifest
    {
        foreach ($this->manifestPaths as $manifestPath) {
            if (!is_file($manifestPath)) {
                throw new RuntimeException('operating prompt manifest not found: ' . $manifestPath);
            }
        }

        return new RecallProviderManifest('operating-prompts', '1.0', $this->manifestPaths, required: false);
    }

    public function collect(TaskBrief $task, RecallRootConfig $rootConfig): RecallProviderResult
    {
        $this->manifest();
        $catalog = new OperatingPromptCatalog($this->manifestPaths);
        $history = new OperatingPromptOutcomeHistory();
        $facts = [];
        $seenRequests = [];

        foreach ($task->operatingPrompts as $request) {
            if (isset($seenRequests[$request->id])) {
                throw new RuntimeException('task selects operating prompt more than once: ' . $request->id);
            }
            $seenRequests[$request->id] = true;

            $preview = $catalog->preview($request);
            if (!$preview->validation->valid || $preview->content === null) {
                throw new RuntimeException(implode('; ', $preview->validation->errors));
            }
            $recipe = $catalog->recipe($request->id);

            $facts[] = new RecallFact(
                'operating-prompt.' . $request->id,
                'operating_prompt',
                $task->status === 'approved' ? 'approved_session_brief' : 'task_input',
                $recipe->sourceRef,
                $task->scopes === [] ? $task->files : $task->scopes,
                [
                    'prompt_id' => $request->id,
                    'level' => $recipe->level,
                    'arguments' => $request->arguments,
                    'content' => $preview->content,
                    'template_sha256' => $recipe->templateSha256,
                    'outcome_stats' => $history->stats($rootConfig->root, $request->id),
                ],
            );
        }

        return new RecallProviderResult(
            CanonicalJson::digest([
                'provider' => 'operating-prompts',
                'facts' => array_map(static fn (RecallFact $fact): array => $fact->toArray(), $facts),
            ]),
            $facts,
        );
    }
}
