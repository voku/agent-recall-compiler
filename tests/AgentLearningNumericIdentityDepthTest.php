<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentRecallCompiler\Provider\AgentLearningNoteProjectionSource;

final class AgentLearningNumericIdentityDepthTest extends TestCase
{
    public function testLosslessOwnerDepthPreservesNumericTaskIdAsString(): void
    {
        $selection = (new AgentLearningNoteProjectionSource(LosslessNumericLearningLineageService::class))->forTask(
            '/tmp/learning',
            '403',
        );

        self::assertSame(
            [
                [
                    'identity_id' => '403',
                    'depth' => 0,
                ],
            ],
            $selection->identityDepths,
        );
        self::assertSame($selection->identityDepths, $selection->observation()['identity_depths']);
    }

    public function testLegacyDepthMapRemainsReadableForNumericTaskId(): void
    {
        $selection = (new AgentLearningNoteProjectionSource(LegacyNumericLearningLineageService::class))->forTask(
            '/tmp/learning',
            '403',
        );

        self::assertSame(
            [
                [
                    'identity_id' => '403',
                    'depth' => 0,
                ],
            ],
            $selection->identityDepths,
        );
    }

    public function testDuplicateLosslessIdentityDepthFailsExplicitly(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('duplicate identity depth');

        (new AgentLearningNoteProjectionSource(DuplicateNumericLearningLineageService::class))->forTask(
            '/tmp/learning',
            '403',
        );
    }
}

final class LosslessNumericLearningLineageService
{
    public function precedentsForTask(
        string $learningRoot,
        string $taskId,
        ?string $projectRoot = null,
    ): NumericLearningTaskPrecedentResult {
        return new NumericLearningTaskPrecedentResult($taskId, lossless: true, duplicate: false);
    }
}

final class LegacyNumericLearningLineageService
{
    public function precedentsForTask(
        string $learningRoot,
        string $taskId,
        ?string $projectRoot = null,
    ): NumericLearningTaskPrecedentResult {
        return new NumericLearningTaskPrecedentResult($taskId, lossless: false, duplicate: false);
    }
}

final class DuplicateNumericLearningLineageService
{
    public function precedentsForTask(
        string $learningRoot,
        string $taskId,
        ?string $projectRoot = null,
    ): NumericLearningTaskPrecedentResult {
        return new NumericLearningTaskPrecedentResult($taskId, lossless: true, duplicate: true);
    }
}

final readonly class NumericLearningTaskPrecedentResult
{
    public function __construct(
        private string $taskId,
        private bool $lossless,
        private bool $duplicate,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $lineage = [
            'identity_id' => $this->taskId,
            'identity_ids' => [],
            'relations' => [],
            'maximum_depth' => 3,
            'maximum_results' => 100,
            'truncated' => false,
        ];

        if ($this->lossless) {
            $lineage['identity_depths'] = [
                [
                    'identity_id' => $this->taskId,
                    'depth' => 0,
                ],
            ];
            if ($this->duplicate) {
                $lineage['identity_depths'][] = [
                    'identity_id' => $this->taskId,
                    'depth' => 1,
                ];
            }

            // Deliberately malformed legacy field: the lossless owner projection must win.
            $lineage['depth_by_identity_id'] = ['ignored' => 'invalid'];
        } else {
            $lineage['depth_by_identity_id'] = [$this->taskId => 0];
        }

        return [
            'task_id' => $this->taskId,
            'precedents' => [],
            'lineage' => $lineage,
            'precedents_truncated' => false,
        ];
    }
}
