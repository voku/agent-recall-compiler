<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Output;

/**
 * One persisted Recall facts document.
 *
 * Recall owns the document schema and canonical bundle identity. Consumers own
 * the meaning they assign to individual fact types and payloads.
 */
final readonly class RecallFactsDocument
{
    /** @param list<RecallFact> $facts */
    public function __construct(
        public string $identityPath,
        public string $bundleSha256,
        public array $facts,
    ) {
    }
}
