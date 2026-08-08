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
     * @param list<OperatingPromptOutcomeEvent> $operatingPromptOutcomeEvents
     */
    public function append(string $root, array $selectionEvents, array $outcomeEvents, array $operatingPromptOutcomeEvents = []): void
    {
        $historyDir = $root . '/history';
        if (!is_dir($historyDir) && !mkdir($historyDir, 0777, true) && !is_dir($historyDir)) {
            throw new RuntimeException('cannot create history directory: ' . $historyDir);
        }

        $selectionPath = $historyDir . '/recall-selections.jsonl';
        $outcomePath = $historyDir . '/outcomes.jsonl';
        $operatingPromptOutcomePath = $historyDir . '/operating-prompt-outcomes.jsonl';
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
            $this->assertAppendIsUnique($operatingPromptOutcomePath, $operatingPromptOutcomeEvents, 'operating prompt outcome');
            $this->appendRollbackSafe(
                $selectionPath,
                $this->encodeLines($selectionEvents, $selectionPath),
                $outcomePath,
                $this->encodeLines($outcomeEvents, $outcomePath),
                $operatingPromptOutcomePath,
                $this->encodeLines($operatingPromptOutcomeEvents, $operatingPromptOutcomePath),
            );
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
            if (preg_match('/^\d+$/', $suffix) === 1) {
                $max = max($max, (int) $suffix);
            }
        }

        return $datePrefix . sprintf('%03d', $max + 1);
    }

    /**
     * @param list<RecallSelectionEvent>|list<GuidanceOutcomeEvent>|list<OperatingPromptOutcomeEvent> $events
     */
    private function assertAppendIsUnique(string $path, array $events, string $type): void
    {
        $existingIds = [];
        $existingCompilationSubjects = [];
        foreach ($this->records($path) as $record) {
            $id = $record['id'] ?? null;
            if (is_string($id) && $id !== '') {
                $existingIds[$id] = true;
            }
            $compilationId = $record['compilation_id'] ?? null;
            $subjectId = $record['guidance_id'] ?? $record['prompt_id'] ?? null;
            if (is_string($compilationId) && is_string($subjectId)) {
                $existingCompilationSubjects[$compilationId . "\0" . $subjectId] = true;
            }
        }

        $batchIds = [];
        $batchCompilationSubjects = [];
        foreach ($events as $event) {
            $id = $event->id;
            $subjectId = $event instanceof OperatingPromptOutcomeEvent ? $event->promptId : $event->guidanceId;
            $key = $event->compilationId . "\0" . $subjectId;
            if (isset($existingIds[$id]) || isset($batchIds[$id])) {
                throw new RuntimeException(sprintf('duplicate %s event id: %s', $type, $id));
            }
            if (isset($existingCompilationSubjects[$key]) || isset($batchCompilationSubjects[$key])) {
                throw new RuntimeException(sprintf(
                    'duplicate %s event for compilation %s and subject %s',
                    $type,
                    $event->compilationId,
                    $subjectId,
                ));
            }
            $batchIds[$id] = true;
            $batchCompilationSubjects[$key] = true;
        }
    }

    /**
     * @param list<RecallSelectionEvent>|list<GuidanceOutcomeEvent>|list<OperatingPromptOutcomeEvent> $events
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
     * @param list<string> $operatingPromptOutcomeLines
     */
    private function appendRollbackSafe(
        string $selectionPath,
        array $selectionLines,
        string $outcomePath,
        array $outcomeLines,
        string $operatingPromptOutcomePath,
        array $operatingPromptOutcomeLines,
    ): void {
        $selectionOriginal = is_file($selectionPath) ? file_get_contents($selectionPath) : '';
        $outcomeOriginal = is_file($outcomePath) ? file_get_contents($outcomePath) : '';
        $operatingPromptOutcomeOriginal = is_file($operatingPromptOutcomePath) ? file_get_contents($operatingPromptOutcomePath) : '';
        if ($selectionOriginal === false || $outcomeOriginal === false || $operatingPromptOutcomeOriginal === false) {
            throw new RuntimeException('cannot read existing event history before append');
        }

        try {
            if ($selectionLines !== []) {
                $this->appendLines($selectionPath, $selectionLines);
            }
            if ($outcomeLines !== []) {
                $this->appendLines($outcomePath, $outcomeLines);
            }
            if ($operatingPromptOutcomeLines !== []) {
                $this->appendLines($operatingPromptOutcomePath, $operatingPromptOutcomeLines);
            }
        } catch (\Throwable $throwable) {
            file_put_contents($selectionPath, $selectionOriginal);
            file_put_contents($outcomePath, $outcomeOriginal);
            file_put_contents($operatingPromptOutcomePath, $operatingPromptOutcomeOriginal);
            throw $throwable;
        }
    }

    /** @param list<string> $lines */
    private function appendLines(string $path, array $lines): void
    {
        $payload = implode("\n", $lines) . "\n";
        if (file_put_contents($path, $payload, FILE_APPEND) === false) {
            throw new RuntimeException('failed to append event history: ' . $path);
        }
    }

    /** @return list<array<string, mixed>> */
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
