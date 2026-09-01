<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentRecallCompiler\Provider\LearningNotePrecedentProjection;
use voku\AgentRecallCompiler\Provider\LearningNoteProjectionSource;
use voku\AgentRecallCompiler\Provider\LearningNoteRecallProvider;
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

        self::assertCount(2, $result->facts);
        $byId = [];
        foreach ($result->facts as $fact) {
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

    public function testReplayDigestChangesOnlyForEligibleProjectionChanges(): void
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

        self::assertSame($first->sourceDigest, $same->sourceDigest);
        self::assertNotSame($first->sourceDigest, $changed->sourceDigest);
        self::assertSame(
            array_map(static fn ($fact): array => $fact->toArray(), $first->facts),
            array_map(static fn ($fact): array => $fact->toArray(), $this->provider([$eligible, $unrelated])->collect($task, $root)->facts),
        );
    }

    /** @param list<LearningNotePrecedentProjection> $notes */
    private function provider(array $notes): LearningNoteRecallProvider
    {
        $source = new class($notes) implements LearningNoteProjectionSource {
            /** @param list<LearningNotePrecedentProjection> $notes */
            public function __construct(private readonly array $notes)
            {
            }

            public function active(string $learningRoot): array
            {
                return $this->notes;
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
