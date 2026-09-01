<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Provider;

final readonly class LearningNotePrecedentProjection
{
    /**
     * @param list<string> $scope
     * @param list<string> $tags
     * @param list<string> $sourceFindings
     * @param list<string> $sourceProposals
     * @param array<string, mixed> $content
     */
    public function __construct(
        public string $id,
        public string $patternKey,
        public array $scope,
        public array $tags,
        public array $sourceFindings,
        public array $sourceProposals,
        public array $content,
        public string $digest,
        public string $evidenceState,
    ) {
    }
}
