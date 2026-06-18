<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler;

use DateTimeImmutable;
use DateTimeInterface;
use JsonException;
use RuntimeException;

final class EventHistoryWriter
{
    public function __construct(
        private readonly RedactionGuard $redactionGuard = new RedactionGuard(),
    ) {
    }

    /**
     * @param list<RecallSelectionEvent> $selectionEvents
     * @param list<GuidanceOutcomeEvent> $outcomeEvents
     */
    public function append(string $root, array $selectionEvents, array $outcomeEvents): void
    {
        $historyDir = $root . '/history';
        if (!is_dir($historyDir) && !mkdir($historyDir, 0777, true) && !is_dir($historyDir)) {
            throw new RuntimeException('cannot create history directory: ' . $historyDir);
        }

        $selectionPath = $historyDir . '/recall-selections.jsonl';
        $outcomePath = $historyDir . '/outcomes.jsonl';
        $lockPath = $historyDir . '/.event-history.lock';

        $lock = fopen($lockPath, 'c');
        if ($lock === false) {
            throw new RuntimeException('cannot open event history lock: ' . $lockPath);
        }

        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('cannot lock event history: ' . $lockPath);
            }

            $this->assertAppendIsUnique($selectionPath, $selectionEvents, 'selection');
            $this->assertAppendIsUnique($outcomePath, $outcomeEvents, 'outcome');
            $this->appendRollbackSafe($selectionPath, $this->encodeLines($selectionEvents, $selectionPath), $outcomePath, $this->encodeLines($outcomeEvents, $outcomePath));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function nextEventId(string $root, string $fileName, string $prefix): string
    {
        $path = $root . '/history/' . $fileName;
        $datePrefix = $prefix . '.' . (new DateTimeImmutable('now'))->format('Y-m-d') . '.';
        $max = 0;
        foreach ($this->records($path) as $record) {
            $id = $record['id'] ?? null;
            if (!is_string($id) || !str_starts_with($id, $datePrefix)) {
                continue;
            }
            $suffix = substr($id, strlen($datePrefix));
            if (
                is_numeric($suffix)
                &&
                (string)$suffix === (string)(int)$suffix
            ) {
                $max = max($max, (int)$suffix);
            }
        }

        return $datePrefix . sprintf('%03d', $max + 1);
    }

    /**
     * @param list<RecallSelectionEvent>|list<GuidanceOutcomeEvent> $events
     */
    private function assertAppendIsUnique(string $path, array $events, string $type): void
    {
        $existingIds = [];
        $existingCompilationGuidance = [];
        foreach ($this->records($path) as $record) {
            $id = $record['id'] ?? null;
            if (is_string($id) && $id !== '') {
                $existingIds[$id] = true;
            }
            $compilationId = $record['compilation_id'] ?? null;
            $guidanceId = $record['guidance_id'] ?? null;
            if (is_string($compilationId) && is_string($guidanceId)) {
                $existingCompilationGuidance[$compilationId . "\0" . $guidanceId] = true;
            }
        }

        $batchIds = [];
        $batchCompilationGuidance = [];
        foreach ($events as $event) {
            $id = $event->id;
            $key = $event->compilationId . "\0" . $event->guidanceId;
            if (isset($existingIds[$id]) || isset($batchIds[$id])) {
                throw new RuntimeException(sprintf('duplicate %s event id: %s', $type, $id));
            }
            if (isset($existingCompilationGuidance[$key]) || isset($batchCompilationGuidance[$key])) {
                throw new RuntimeException(sprintf(
                    'duplicate %s event for compilation %s and guidance %s',
                    $type,
                    $event->compilationId,
                    $event->guidanceId,
                ));
            }
            $batchIds[$id] = true;
            $batchCompilationGuidance[$key] = true;
        }
    }

    /**
     * @param list<RecallSelectionEvent>|list<GuidanceOutcomeEvent> $events
     * @return list<string>
     */
    private function encodeLines(array $events, string $path): array
    {
        $lines = [];
        foreach ($events as $event) {
            $data = $event->toArray();
            $this->redactionGuard->assertSafe($data, $path, null, $event->id);
            $lines[] = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }

        return $lines;
    }

    /**
     * @param list<string> $selectionLines
     * @param list<string> $outcomeLines
     */
    private function appendRollbackSafe(string $selectionPath, array $selectionLines, string $outcomePath, array $outcomeLines): void
    {
        $selectionOriginal = is_file($selectionPath) ? file_get_contents($selectionPath) : '';
        $outcomeOriginal = is_file($outcomePath) ? file_get_contents($outcomePath) : '';
        if ($selectionOriginal === false || $outcomeOriginal === false) {
            throw new RuntimeException('cannot read existing event history before append');
        }

        try {
            if ($selectionLines !== []) {
                $this->appendLines($selectionPath, $selectionLines);
            }
            if ($outcomeLines !== []) {
                $this->appendLines($outcomePath, $outcomeLines);
            }
        } catch (\Throwable $throwable) {
            file_put_contents($selectionPath, $selectionOriginal);
            file_put_contents($outcomePath, $outcomeOriginal);
            throw $throwable;
        }
    }

    /**
     * @param list<string> $lines
     */
    private function appendLines(string $path, array $lines): void
    {
        $payload = implode("\n", $lines) . "\n";
        if (file_put_contents($path, $payload, FILE_APPEND) === false) {
            throw new RuntimeException('failed to append event history: ' . $path);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function records(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new RuntimeException('cannot read event history: ' . $path);
        }

        $records = [];
        foreach ($lines as $index => $line) {
            if (trim($line) === '') {
                continue;
            }
            try {
                $record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException(sprintf('malformed JSONL in %s:%d: %s', $path, $index + 1, $exception->getMessage()));
            }
            if (!is_array($record)) {
                throw new RuntimeException(sprintf('JSONL record must be an object in %s:%d', $path, $index + 1));
            }
            if (($record['schema_version'] ?? '1.0') !== '1.0') {
                throw new RuntimeException(sprintf('unsupported event schema version in %s:%d', $path, $index + 1));
            }
            $recordedAt = $record['recorded_at'] ?? $record['created_at'] ?? null;
            if (is_string($recordedAt) && DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $recordedAt) === false) {
                throw new RuntimeException(sprintf('malformed event timestamp in %s:%d', $path, $index + 1));
            }
            /** @var array<string, mixed> $record */
            $records[] = $record;
        }

        return $records;
    }
}
