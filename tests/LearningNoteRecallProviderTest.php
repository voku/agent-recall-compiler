<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentRecallCompiler\Provider\LearningNotePrecedentProjection;
use voku\AgentRecallCompiler\Provider\LearningNoteProjectionSource;
use voku\AgentRecallCompiler\Provider\LearningNoteRecallProvider;
use voku\AgentRecallCompiler\Provider\LearningTaskPrecedentProjection;
use voku\AgentRecallCompiler\Provider\TaskAwareLearningNoteProjectionSource;
use voku\AgentRecallCompiler\RecallCompilationBlockedException;
use voku\AgentRecallCompiler\RecallRootConfig;
use voku\AgentRecallCompiler\TaskBrief;

final class LearningNoteRecallProviderTest extends TestCase
{
    public function testFileScopeAndTagOnlySelectionAreDeterministic(): void
    {
        $provider = $this->provider([
            $this->note('learning-note.file', ['src/Auth'], ['other']),
            $this->note('learning-note.tag', ['docs/'], ['security']),
            $this->note('learning-note.none', ['tests/'], ['other']),
        ]);
        $result = $provider->collect(
            new TaskBrief('TASK-145', 'Auth change', ['src/Auth/Login.php'], tags: ['security']),
            new RecallRootConfig('/tmp/learning', 'constraints/active'),
        );

        $precedents = array_values(array_filter(
            $result->facts,
            static fn ($fact): bool => $fact->type === 'learning_precedent',
        ));
        self::assertCount(2, $precedents);
        $byId = [];
        foreach ($precedents as $fact) {
            $byId[$fact->payload['note_id']] = $fact->payload;
        }
        self::assertSame(['scope_match'], $byId['learning-note.file']['match_reasons']);
        self::assertSame(['tag_match'], $byId['learning-note.tag']['match_reasons']);
        self::assertArrayNotHasKey('learning-note.none', $byId);
    }

    public function testCurrentSpecificPrecedentWinsRenderingBudgetBeforeGlobalAndStale(): void
    {
        $notes = [
            $this->note('learning-note.global', ['*'], ['security']),
            $this->note('learning-note.stale', ['src/Auth/Login.php'], ['security'], 'review_needed'),
        ];
        for ($i = 1; $i <= 6; ++$i) {
            $notes[] = $this->note('learning-note.specific-' . $i, ['src/Auth/Login.php'], ['security']);
        }

        $result = $this->provider($notes)->collect(
            new TaskBrief('TASK-145', 'Auth change', ['src/Auth/Login.php'], tags: ['security']),
            new RecallRootConfig('/tmp/learning', 'constraints/active'),
        );

        $rendered = [];
        $budgetOmitted = [];
        $stale = null;
        foreach ($result->facts as $fact) {
            if ($fact->type !== 'learning_precedent') {
                continue;
            }
            if (($fact->payload['render'] ?? false) === true) {
                $rendered[] = $fact->payload['note_id'];
            }
            if (($fact->payload['omission_reason'] ?? null) === 'context_budget') {
                $budgetOmitted[] = $fact->payload['note_id'];
            }
            if (($fact->payload['note_id'] ?? null) === 'learning-note.stale') {
                $stale = $fact->payload;
            }
        }

        self::assertCount(5, $rendered);
        self::assertContains('learning-note.specific-1', $rendered);
        self::assertContains('learning-note.specific-5', $rendered);
        self::assertContains('learning-note.specific-6', $budgetOmitted);
        self::assertContains('learning-note.global', $budgetOmitted);
        self::assertIsArray($stale);
        self::assertFalse($stale['render']);
        self::assertSame('review_needed', $stale['omission_reason']);
        self::assertSame([], $stale['content']);
    }

    public function testBoundedOwnerObservationSurvivesEmptyRecallSelection(): void
    {
        $result = $this->provider([
            $this->note('learning-note.unrelated', ['docs/'], ['other']),
        ], truncated: true)->collect(
            new TaskBrief('TASK-145', 'Source change', ['src/File.php']),
            new RecallRootConfig('/tmp/learning', 'constraints/active'),
        );

        self::assertCount(1, $result->facts);
        $observation = $result->facts[0];
        self::assertSame('learning_precedent_observation', $observation->type);
        self::assertSame('TASK-145', $observation->payload['task_id']);
        self::assertSame('exact_task_lineage', $observation->payload['observation_scope']);
        self::assertSame(3, $observation->payload['maximum_depth']);
        self::assertSame(100, $observation->payload['maximum_results']);
        self::assertTrue($observation->payload['truncated']);
        self::assertSame(['learning-note.unrelated'], $observation->payload['identity_ids']);
    }

    public function testSourceMissingBlocksInsteadOfBecomingEmptySuccess(): void
    {
        $this->expectException(RecallCompilationBlockedException::class);
        $this->expectExceptionMessage('references missing repository evidence');

        $this->provider([
            $this->note('learning-note.missing', ['src/'], [], 'source_missing'),
        ])->collect(
            new TaskBrief('TASK-145', 'Change', ['src/File.php']),
            new RecallRootConfig('/tmp/learning', 'constraints/active'),
        );
    }

    public function testReplayDigestChangesOnlyForObservedOrEligibleProjectionChanges(): void
    {
        $task = new TaskBrief('TASK-145', 'Change', ['src/File.php']);
        $root = new RecallRootConfig('/tmp/learning', 'constraints/active');
        $eligible = $this->note('learning-note.eligible', ['src/'], [], 'current', str_repeat('a', 64));
        $unrelated = $this->note('learning-note.unrelated', ['docs/'], [], 'current', str_repeat('b', 64));

        $first = $this->provider([$eligible, $unrelated])->collect($task, $root);
        $same = $this->provider([
            $eligible,
            $this->note('learning-note.unrelated', ['docs/'], [], 'current', str_repeat('c', 64)),
        ])->collect($task, $root);
        $changed = $this->provider([
            $this->note('learning-note.eligible', ['src/'], [], 'current', str_repeat('d', 64)),
            $unrelated,
        ])->collect($task, $root);
        $truncated = $this->provider([$eligible, $unrelated], truncated: true)->collect($task, $root);

        self::assertSame($first->sourceDigest, $same->sourceDigest);
        self::assertNotSame($first->sourceDigest, $changed->sourceDigest);
        self::assertNotSame($first->sourceDigest, $truncated->sourceDigest);
        self::assertSame(
            array_map(static fn ($fact): array => $fact->toArray(), $first->facts),
            array_map(static fn ($fact): array => $fact->toArray(), $this->provider([$eligible, $unrelated])->collect($task, $root)->facts),
        );
    }

    public function testStandaloneRecallBehaviorRemainsValidWhenLearningIsAbsent(): void
    {
        $source = new class implements LearningNoteProjectionSource {
            public function isAvailable(): bool
            {
                return false;
            }

            public function forTask(
                string $learningRoot,
                string $taskId,
                ?string $projectRoot = null,
            ): LearningTaskPrecedentProjection {
                throw new \RuntimeException('Should not be called when unavailable');
            }
        };

        $provider = new LearningNoteRecallProvider($source);
        $rootConfig = new RecallRootConfig('/tmp/learning', 'constraints/active');
        self::assertFalse($provider->isAvailable($rootConfig));
    }

    public function testBoundedOwnerApiIsUsedWhenLearningIsInstalled(): void
    {
        $requestedTaskId = null;
        $source = new class($requestedTaskId) implements LearningNoteProjectionSource {
            public function __construct(public ?string &$requestedTaskId)
            {
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function forTask(
                string $learningRoot,
                string $taskId,
                ?string $projectRoot = null,
            ): LearningTaskPrecedentProjection {
                $this->requestedTaskId = $taskId;

                return new LearningTaskPrecedentProjection(
                    taskId: $taskId,
                    precedents: [],
                    identityIds: [],
                    depthByIdentityId: [$taskId => 0],
                    relations: [],
                    maximumDepth: 3,
                    maximumResults: 100,
                    truncated: false,
                );
            }
        };

        $provider = new LearningNoteRecallProvider($source);
        $task = new TaskBrief('TASK-CANONICAL-456', 'Description', ['src/File.php']);
        $root = new RecallRootConfig(__DIR__, 'constraints/active');

        self::assertTrue($provider->isAvailable($root));
        $result = $provider->collect($task, $root);
        self::assertSame('TASK-CANONICAL-456', $requestedTaskId);
        self::assertCount(1, $result->facts);
        self::assertSame('learning_precedent_observation', $result->facts[0]->type);
    }

    public function testTaskBriefContextIsForwardedToTaskAwareOwnerSource(): void
    {
        $source = new class implements TaskAwareLearningNoteProjectionSource {
            /** @var list<string> */
            public array $taskFiles = [];

            /** @var list<string> */
            public array $taskTags = [];

            public function isAvailable(): bool
            {
                return true;
            }

            public function forTask(
                string $learningRoot,
                string $taskId,
                ?string $projectRoot = null,
            ): LearningTaskPrecedentProjection {
                throw new \RuntimeException('Legacy owner path should not be used for a task-aware source.');
            }

            public function forTaskWithContext(
                string $learningRoot,
                string $taskId,
                ?string $projectRoot = null,
                array $taskFiles = [],
                array $taskTags = [],
            ): LearningTaskPrecedentProjection {
                $this->taskFiles = $taskFiles;
                $this->taskTags = $taskTags;

                return new LearningTaskPrecedentProjection(
                    taskId: $taskId,
                    precedents: [],
                    identityIds: [],
                    depthByIdentityId: [$taskId => 0],
                    relations: [],
                    maximumDepth: 3,
                    maximumResults: 100,
                    truncated: false,
                );
            }
        };

        (new LearningNoteRecallProvider($source))->collect(
            new TaskBrief('TASK-152', 'Bounded precedent task', ['src/Auth/Login.php'], tags: ['SECURITY']),
            new RecallRootConfig('/tmp/learning', 'constraints/active'),
        );

        self::assertSame(['src/Auth/Login.php'], $source->taskFiles);
        self::assertSame(['SECURITY'], $source->taskTags);
    }

    public function testOwnerFailurePropagatesOutThroughRecall(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Owner state is stale');

        $source = new class implements LearningNoteProjectionSource {
            public function isAvailable(): bool
            {
                return true;
            }

            public function forTask(
                string $learningRoot,
                string $taskId,
                ?string $projectRoot = null,
            ): LearningTaskPrecedentProjection {
                throw new \RuntimeException('Owner state is stale');
            }
        };

        (new LearningNoteRecallProvider($source))->collect(
            new TaskBrief('TASK-145', 'Change', ['src/File.php']),
            new RecallRootConfig('/tmp/learning', 'constraints/active'),
        );
    }

    public function testUnrelatedLearningNoteOutsideTaskLineageIsNotConsideredByRecall(): void
    {
        $inLineageNote = $this->note('learning-note.in-lineage', ['src/'], []);
        $result = $this->provider([$inLineageNote])->collect(
            new TaskBrief('TASK-145', 'Change', ['src/File.php']),
            new RecallRootConfig('/tmp/learning', 'constraints/active'),
        );

        $precedentIds = [];
        foreach ($result->facts as $fact) {
            if ($fact->type === 'learning_precedent') {
                $precedentIds[] = $fact->payload['note_id'];
            }
        }
        self::assertSame(['learning-note.in-lineage'], $precedentIds);
    }

    public function testInstrumentationExposesCandidateAndSelectionCounts(): void
    {
        $eligible1 = $this->note('learning-note.eligible-1', ['src/Auth/'], ['auth']);
        $eligible2 = $this->note('learning-note.eligible-2', ['src/Auth/'], ['auth']);
        $unrelated = $this->note('learning-note.unrelated', ['docs/'], ['other']);

        $result = $this->provider([$eligible1, $eligible2, $unrelated])->collect(
            new TaskBrief('TASK-145', 'Auth change', ['src/Auth/Login.php'], tags: ['auth']),
            new RecallRootConfig('/tmp/learning', 'constraints/active'),
        );

        $observation = $result->facts[0];
        self::assertSame('learning_precedent_observation', $observation->type);
        self::assertSame(3, $observation->payload['candidates_returned']);
        self::assertSame(2, $observation->payload['candidates_considered']);
        self::assertSame(2, $observation->payload['precedents_selected']);
        self::assertSame(
            ['learning-note.eligible-1', 'learning-note.eligible-2'],
            $observation->payload['selected_precedent_ids'],
        );
    }

    /** @param list<LearningNotePrecedentProjection> $notes */
    private function provider(array $notes, bool $truncated = false): LearningNoteRecallProvider
    {
        $source = new class($notes, $truncated) implements LearningNoteProjectionSource {
            /** @var list<\voku\AgentRecallCompiler\Provider\LearningNotePrecedentProjection> */
            private readonly array $notes;

            /** @param list<\voku\AgentRecallCompiler\Provider\LearningNotePrecedentProjection> $notes */
            public function __construct(array $notes, private readonly bool $truncated)
            {
                $this->notes = $notes;
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function forTask(
                string $learningRoot,
                string $taskId,
                ?string $projectRoot = null,
            ): LearningTaskPrecedentProjection {
                $identityIds = array_map(
                    static fn (LearningNotePrecedentProjection $note): string => $note->id,
                    $this->notes,
                );
                $depths = [$taskId => 0];
                foreach ($identityIds as $identityId) {
                    $depths[$identityId] = 1;
                }

                return new LearningTaskPrecedentProjection(
                    taskId: $taskId,
                    precedents: $this->notes,
                    identityIds: $identityIds,
                    depthByIdentityId: $depths,
                    relations: [],
                    maximumDepth: 3,
                    maximumResults: 100,
                    truncated: $this->truncated,
                );
            }
        };

        return new LearningNoteRecallProvider($source);
    }

    /**
     * @param list<string> $scope
     * @param list<string> $tags
     */
    private function note(
        string $id,
        array $scope,
        array $tags,
        string $state = 'current',
        string $digest = '',
    ): LearningNotePrecedentProjection {
        if ($digest === '') {
            $digest = hash('sha256', $id);
        }

        return new LearningNotePrecedentProjection(
            id: $id,
            patternKey: 'pattern.' . $id,
            scope: $scope,
            tags: $tags,
            sourceFindings: ['finding.' . $id],
            sourceProposals: [],
            content: [
                'title' => 'Title ' . $id,
                'context' => 'Historical context for ' . $id,
                'guidance' => 'Prior guidance for ' . $id,
                'why_it_works' => 'Because the prior case proved it.',
                'when_to_apply' => 'When the same bounded condition exists.',
                'when_not_to_apply' => 'When stronger current evidence differs.',
                'verification' => 'Inspect current source.',
                'failed_approaches' => ['Do not repeat the failed approach.'],
            ],
            digest: $digest,
            evidenceState: $state,
        );
    }
}
