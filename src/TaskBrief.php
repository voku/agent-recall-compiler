<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler;

final readonly class TaskBrief
{
    /**
     * @param list<string> $files
     * @param list<string> $scopes
     * @param list<string> $nonGoals
     * @param list<string> $validation
     * @param list<string> $tags Project-defined relevance labels (domain, system, capability,
     *        or any other taxonomy a project chooses). Matched against fact/guidance tags
     *        independently of path scope, so relevance is not tied to a directory layout.
     * @param list<string> $behaviorAnchors Concrete runtime/request/consumer boundaries
     *        that should be inspected or verified when the task changes behavior.
     * @param list<string> $targets Exact agent-map method targets in Class::method form.
     * @param list<OperatingPromptRequest> $operatingPrompts Versioned operating-prompt
     *        requests selected by the task. Definitions are resolved by a manifest provider.
     * @param list<string> $acceptanceCriteria Required outcomes from the approved task contract.
     *        Their presence is not evidence that they are satisfied.
     */
    public function __construct(
        public string $id,
        public string $description,
        public array $files,
        public array $scopes = [],
        public array $nonGoals = [],
        public array $validation = [],
        public ?string $status = null,
        public ?int $revision = null,
        public ?string $sourcePath = null,
        public array $tags = [],
        public array $behaviorAnchors = [],
        public array $targets = [],
        public array $operatingPrompts = [],
        public ?GovernedRunBinding $governedRun = null,
        public array $acceptanceCriteria = [],
    ) {
    }
}
