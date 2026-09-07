<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Provider;

/** Recall-side typed projection of Learning's bounded task-precedent observation. */
final readonly class LearningTaskPrecedentProjection
{
    /**
     * @param list<LearningNotePrecedentProjection> $precedents
     * @param list<string> $identityIds
     * @param array<string, int> $depthByIdentityId
     * @param list<array{source_id: string, kind: string, target_id: string}> $relations
     */
    public function __construct(
        public string $taskId,
        public array $precedents,
        public array $identityIds,
        public array $depthByIdentityId,
        public array $relations,
        public int $maximumDepth,
        public int $maximumResults,
        public bool $truncated,
    ) {
    }

    /**
     * @return array{
     *   task_id: string,
     *   identity_ids: list<string>,
     *   depth_by_identity_id: array<string, int>,
     *   relations: list<array{source_id: string, kind: string, target_id: string}>,
     *   maximum_depth: int,
     *   maximum_results: int,
     *   truncated: bool
     * }
     */
    public function observation(): array
    {
        return [
            'task_id' => $this->taskId,
            'identity_ids' => $this->identityIds,
            'depth_by_identity_id' => $this->depthByIdentityId,
            'relations' => $this->relations,
            'maximum_depth' => $this->maximumDepth,
            'maximum_results' => $this->maximumResults,
            'truncated' => $this->truncated,
        ];
    }
}
