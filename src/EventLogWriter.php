<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler;

/** Compatibility facade for the centralized immutable event writer. */
final readonly class EventLogWriter
{
    public function __construct(private EventHistoryWriter $writer = new EventHistoryWriter())
    {
    }

    /**
     * @param list<RecallSelectionEvent> $selectionEvents
     * @param list<GuidanceOutcomeEvent> $outcomeEvents
     */
    public function append(string $root, array $selectionEvents, array $outcomeEvents): void
    {
        $this->writer->append($root, $selectionEvents, $outcomeEvents);
    }

    public function nextEventId(string $root, string $fileName, string $prefix): string
    {
        return $this->writer->nextEventId($root, $fileName, $prefix);
    }
}
