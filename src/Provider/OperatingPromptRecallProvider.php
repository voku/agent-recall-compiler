<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Provider;

use RuntimeException;
use voku\AgentRecallCompiler\CanonicalJson;
use voku\AgentRecallCompiler\OperatingPromptRequest;
use voku\AgentRecallCompiler\RecallRootConfig;
use voku\AgentRecallCompiler\TaskBrief;

/**
 * Instantiates task-selected prompt recipes from versioned local manifests.
 *
 * The provider owns deterministic loading and substitution only. Prompt semantics
 * stay in the manifest's owning repository rather than being duplicated in PHP.
 */
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
        $definitions = $this->loadDefinitions();
        $facts = [];
        $seenRequests = [];

        foreach ($task->operatingPrompts as $request) {
            if (isset($seenRequests[$request->id])) {
                throw new RuntimeException('task selects operating prompt more than once: ' . $request->id);
            }
            $seenRequests[$request->id] = true;

            $definition = $definitions[$request->id] ?? null;
            if ($definition === null) {
                throw new RuntimeException('unknown operating prompt id: ' . $request->id);
            }

            $rendered = $this->render($request, $definition['template']);
            $facts[] = new RecallFact(
                'operating-prompt.' . $request->id,
                'operating_prompt',
                $task->status === 'approved' ? 'approved_session_brief' : 'task_input',
                $definition['source_ref'],
                $task->scopes === [] ? $task->files : $task->scopes,
                [
                    'prompt_id' => $request->id,
                    'level' => $definition['level'],
                    'arguments' => $request->arguments,
                    'content' => $rendered,
                    'template_sha256' => $definition['template_sha256'],
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

    /**
     * @return array<string, array{level: 1|2, template: string, source_ref: string, template_sha256: string}>
     */
    private function loadDefinitions(): array
    {
        $definitions = [];
        foreach ($this->manifestPaths as $manifestPath) {
            $content = file_get_contents($manifestPath);
            if ($content === false) {
                throw new RuntimeException('cannot read operating prompt manifest: ' . $manifestPath);
            }
            try {
                $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new RuntimeException('invalid operating prompt manifest ' . $manifestPath . ': ' . $exception->getMessage(), 0, $exception);
            }
            if (!is_array($data) || ($data['schema_version'] ?? null) !== '1.0') {
                throw new RuntimeException('operating prompt manifest must use schema_version "1.0": ' . $manifestPath);
            }
            $prompts = $data['prompts'] ?? null;
            if (!is_array($prompts)) {
                throw new RuntimeException('operating prompt manifest requires a prompts array: ' . $manifestPath);
            }

            foreach ($prompts as $prompt) {
                if (!is_array($prompt)) {
                    throw new RuntimeException('operating prompt manifest entries must be JSON objects: ' . $manifestPath);
                }
                $id = $prompt['id'] ?? null;
                if (!is_string($id) || preg_match('/\A[a-z][a-z0-9._-]*\z/', $id) !== 1) {
                    throw new RuntimeException('operating prompt manifest entry has invalid id: ' . $manifestPath);
                }
                if (isset($definitions[$id])) {
                    throw new RuntimeException('operating prompt id is defined more than once: ' . $id);
                }
                $level = $prompt['level'] ?? null;
                if ($level !== 1 && $level !== 2) {
                    throw new RuntimeException('operating prompt ' . $id . ' requires level 1 or 2');
                }
                $template = $prompt['template'] ?? null;
                if (!is_string($template) || trim($template) === '') {
                    throw new RuntimeException('operating prompt ' . $id . ' requires a non-empty template');
                }
                $template = trim(str_replace(["\r\n", "\r"], "\n", $template));
                $this->placeholderNames($id, $template);
                $definitions[$id] = [
                    'level' => $level,
                    'template' => $template,
                    'source_ref' => $manifestPath . '#' . $id,
                    'template_sha256' => hash('sha256', $template),
                ];
            }
        }

        ksort($definitions, SORT_STRING);

        return $definitions;
    }

    private function render(OperatingPromptRequest $request, string $template): string
    {
        $placeholders = $this->placeholderNames($request->id, $template);
        $argumentNames = array_keys($request->arguments);
        sort($argumentNames, SORT_STRING);

        $missing = array_values(array_diff($placeholders, $argumentNames));
        if ($missing !== []) {
            throw new RuntimeException(sprintf(
                'operating prompt %s is missing arguments: %s',
                $request->id,
                implode(', ', $missing),
            ));
        }

        $extra = array_values(array_diff($argumentNames, $placeholders));
        if ($extra !== []) {
            throw new RuntimeException(sprintf(
                'operating prompt %s received unknown arguments: %s',
                $request->id,
                implode(', ', $extra),
            ));
        }

        $replacements = [];
        foreach ($request->arguments as $name => $value) {
            $replacements['{{' . $name . '}}'] = $this->argumentValue($value);
        }

        return strtr($template, $replacements);
    }

    /** @return list<string> */
    private function placeholderNames(string $id, string $template): array
    {
        preg_match_all('/\{\{([a-z][a-z0-9_]*)\}\}/', $template, $matches);
        /** @var list<string> $names */
        $names = array_values(array_unique($matches[1]));
        sort($names, SORT_STRING);

        $withoutPlaceholders = preg_replace('/\{\{[a-z][a-z0-9_]*\}\}/', '', $template);
        if (!is_string($withoutPlaceholders) || str_contains($withoutPlaceholders, '{{') || str_contains($withoutPlaceholders, '}}')) {
            throw new RuntimeException('operating prompt has malformed placeholder syntax: ' . $id);
        }

        return $names;
    }

    private function argumentValue(bool|int|string $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }
}
