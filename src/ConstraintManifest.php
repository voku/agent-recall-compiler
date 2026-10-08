<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler;

final readonly class ConstraintManifest
{
    /**
     * @param list<string> $scope
     * @param list<string> $validationCommands
     * @param list<string> $tags
     * @param list<string> $subjectPatterns Literal, case-sensitive substrings naming what the rule is about
     *        (for example a function or class name). When present, a scope or tag match alone no longer
     *        selects the constraint: the task text or a touched file must also contain one of them. This
     *        keeps a rule with a broad directory scope out of tasks that never touch its subject.
     *        Empty means the constraint is selected by scope and tags only.
     */
    public function __construct(
        public string $id,
        public string $engine,
        public string $ruleIdentifier,
        public array $scope,
        public array $validationCommands,
        public string $sourceProposal,
        public string $status,
        public array $tags = [],
        public array $subjectPatterns = [],
    ) {
    }
}
