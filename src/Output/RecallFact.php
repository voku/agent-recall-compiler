<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Output;

/**
 * One compiled Recall fact.
 *
 * Recall owns how facts are serialized; the consuming host owns what a fact
 * type means to its own workflow, so the payload stays deliberately open while
 * common provenance/scope fields remain typed.
 */
final readonly class RecallFact
{
    /**
     * @param list<string> $scope
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $type,
        public array $payload,
        public ?string $sourceRef = null,
        public array $scope = [],
    ) {
    }
}
