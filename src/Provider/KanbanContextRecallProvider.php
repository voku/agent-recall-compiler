<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Provider;

use RuntimeException;
use voku\AgentRecallCompiler\CanonicalJson;
use voku\AgentRecallCompiler\KanbanContextProjection;
use voku\AgentRecallCompiler\RecallRootConfig;
use voku\AgentRecallCompiler\TaskBrief;

/**
 * Consumes a stable board-fact projection supplied either through the legacy
 * standalone CLI path or directly by an embedding host. It intentionally does
 * not parse board Markdown or link against agent-kanban: board ownership
 * remains there and this compiler only sees bounded facts.
 */
final class KanbanContextRecallProvider implements RecallProvider
{
    public function __construct(private readonly string|KanbanContextProjection $context)
    {
    }

    public function manifest(): RecallProviderManifest
    {
        if ($this->context instanceof KanbanContextProjection) {
            return new RecallProviderManifest('kanban-context', '1.0', [$this->context->sourcePath], required: false);
        }

        if ($this->isInlineProjection($this->context)) {
            $data = $this->decodeContext($this->context, 'inline kanban context');
            $source = $data['source'] ?? null;
            $sourcePath = is_array($source) ? ($source['path'] ?? null) : null;

            return new RecallProviderManifest(
                'kanban-context',
                '1.0',
                is_string($sourcePath) && trim($sourcePath) !== '' ? [$sourcePath] : [],
                required: false,
            );
        }

        return new RecallProviderManifest('kanban-context', '1.0', [$this->context], required: false);
    }

    public function collect(TaskBrief $task, RecallRootConfig $rootConfig): RecallProviderResult
    {
        $data = match (true) {
            $this->context instanceof KanbanContextProjection => $this->context->toArray(),
            $this->isInlineProjection($this->context) => $this->decodeContext($this->context, 'inline kanban context'),
            default => $this->readContextFile($this->context),
        };

        if (($data['schema_version'] ?? null) !== '1.0') {
            throw new RuntimeException('kanban context must use schema_version "1.0"');
        }
        if (($data['task_id'] ?? null) !== $task->id) {
            throw new RuntimeException('kanban context task_id does not match task brief: ' . $task->id);
        }
        $source = $data['source'] ?? null;
        $card = $data['card'] ?? null;
        if (!is_array($source) || !is_array($card)) {
            throw new RuntimeException('kanban context requires source and card objects');
        }
        $sourcePath = $source['path'] ?? null;
        $revision = $source['revision'] ?? null;
        if (!is_string($sourcePath) || trim($sourcePath) === '' || !is_string($revision) || trim($revision) === '') {
            throw new RuntimeException('kanban context source requires non-empty path and revision');
        }

        return new RecallProviderResult(
            CanonicalJson::digest($data),
            [new RecallFact(
                'kanban.' . $task->id,
                'kanban',
                'kanban_board',
                $sourcePath,
                $task->files,
                [
                    'source_revision' => $revision,
                    'card' => $card,
                ],
                'kanban:' . $task->id,
            )],
        );
    }

    /** @return array<string, mixed> */
    private function readContextFile(string $contextPath): array
    {
        $content = file_get_contents($contextPath);
        if ($content === false) {
            throw new RuntimeException('cannot read kanban context: ' . $contextPath);
        }

        return $this->decodeContext($content, 'kanban context');
    }

    /** @return array<string, mixed> */
    private function decodeContext(string $content, string $label): array
    {
        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('invalid ' . $label . ': ' . $exception->getMessage());
        }
        if (!is_array($data)) {
            throw new RuntimeException($label . ' must decode to an object');
        }

        return $data;
    }

    private function isInlineProjection(string $context): bool
    {
        return str_starts_with(ltrim($context), '{');
    }
}
