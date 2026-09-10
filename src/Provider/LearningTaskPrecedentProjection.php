<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Provider;

/** Recall-side typed projection of Learning's bounded task-precedent observation. */
final readonly class LearningTaskPrecedentProjection
{
    /**
     * @param list<LearningNotePrecedentProjection> $precedents
     * @param list<string> $identityIds
     * @param array<int|string, int> $depthByIdentityId
     * @param list<array{source_id: string, kind: string, target_id: string}> $relations
     * @param list<array{identity_id: string, depth: int}> $identityDepths
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
        public ?bool $precedentsTruncated = null,
        public array $identityDepths = [],
    ) {
    }

    /**
     * @return array{
     *   task_id: string,
     *   identity_ids: list<string>,
     *   depth_by_identity_id: array<int|string, int>,
     *   identity_depths: list<array{identity_id: string, depth: int}>,
     *   relations: list<array{source_id: string, kind: string, target_id: string}>,
     *   maximum_depth: int,
     *   maximum_results: int,
     *   truncated: bool,
     *   precedents_truncated: bool|null
     * }
     */
    public function observation(): array
    {
        return [
            'task_id' => $this->taskId,
            'identity_ids' => $this->identityIds,
            'depth_by_identity_id' => $this->depthByIdentityId,
            'identity_depths' => $this->identityDepths !== [] ? $this->identityDepths : $this->identityDepthsFromMap(),
            'relations' => $this->relations,
            'maximum_depth' => $this->maximumDepth,
            'maximum_results' => $this->maximumResults,
            'truncated' => $this->truncated,
            'precedents_truncated' => $this->precedentsTruncated,
        ];
    }

    /** @return list<array{identity_id: string, depth: int}> */
    private function identityDepthsFromMap(): array
    {
        $result = [];
        foreach ($this->depthByIdentityId as $identityId => $depth) {
            $result[] = [
                'identity_id' => (string) $identityId,
                'depth' => $depth,
            ];
        }

        return $result;
    }
}
