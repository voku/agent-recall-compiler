<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Provider;

use RuntimeException;
use voku\AgentRecallCompiler\CanonicalJson;
use voku\AgentRecallCompiler\RecallRootConfig;
use voku\AgentRecallCompiler\TaskBrief;

/**
 * Consumes the stable JSON projection made by the orchestration layer. It
 * intentionally does not parse board Markdown or link against agent-kanban:
 * board ownership remains there and this compiler only sees facts.
 */
final class KanbanContextRecallProvider implements RecallProvider
{
    public function __construct(private readonly string $contextPath)
    {
    }

    public function manifest(): RecallProviderManifest
    {
        return new RecallProviderManifest('kanban-context', '1.0', [$this->contextPath], required: false);
    }

    public function collect(TaskBrief $task, RecallRootConfig $rootConfig): RecallProviderResult
    {
        $content = file_get_contents($this->contextPath);
        if ($content === false) {
            throw new RuntimeException('cannot read kanban context: ' . $this->contextPath);
        }
        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('invalid kanban context: ' . $exception->getMessage());
        }
        if (!is_array($data) || ($data['schema_version'] ?? null) !== '1.0') {
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
}
