<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use voku\AgentRecallCompiler\LearningPrecedentPreview;
use voku\AgentRecallCompiler\Output\RenderedLearningPrecedent;
use voku\AgentRecallCompiler\Provider\LearningNotePrecedentProjection;
use voku\AgentRecallCompiler\Provider\LearningNoteProjectionSource;
use voku\AgentRecallCompiler\Provider\LearningNoteRecallProvider;
use voku\AgentRecallCompiler\Provider\LearningTaskPrecedentProjection;
use voku\AgentRecallCompiler\RecallCompilationBlockedException;

final class LearningPrecedentPreviewTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/recall-preview-' . bin2hex(random_bytes(4)) . '/.agent-loop/learning';
        mkdir($this->root, 0o777, true);
    }

    protected function tearDown(): void
    {
        rmdir($this->root);
        rmdir(dirname($this->root));
        rmdir(dirname($this->root, 2));
    }

    public function testCandidateScopePreviewsOnlyRenderedPrecedentsWithTheirGuidance(): void
    {
        $preview = new LearningPrecedentPreview($this->provider([
            $this->note('learning-note.gate', ['src/Workflow/WorkflowCloseReadinessInspector.php']),
            $this->note('learning-note.stale', ['src/Workflow'], 'review_needed'),
            $this->note('learning-note.other', ['docs/']),
        ]));

        $precedents = $preview->forCandidateScope(
            $this->root,
            'REL-003',
            ['src/Workflow/WorkflowCloseReadinessInspector.php', 'tests/WorkflowCloseCommandTest.php'],
        );

        self::assertSame(['learning-note.gate'], array_map(
            static fn (RenderedLearningPrecedent $p): string => $p->noteId,
            $precedents,
        ));
        self::assertSame('Prior guidance for learning-note.gate', $precedents[0]->guidance);
        self::assertSame('Title learning-note.gate', $precedents[0]->title);
        self::assertSame('pattern.learning-note.gate', $precedents[0]->patternKey);
        self::assertSame(['src/Workflow/WorkflowCloseReadinessInspector.php'], $precedents[0]->matchingTaskFiles);
    }

    public function testPreviewUsesTheBriefingRankNotIdentityOrder(): void
    {
        $preview = new LearningPrecedentPreview($this->provider([
            $this->note('learning-note.a', ['src/Workflow']),
            $this->note('learning-note.z', ['src/Workflow/WorkflowCloseReadinessInspector.php']),
        ]));

        $precedents = $preview->forCandidateScope($this->root, 'REL-003', ['src/Workflow/WorkflowCloseReadinessInspector.php']);

        self::assertSame(['learning-note.z', 'learning-note.a'], array_map(
            static fn (RenderedLearningPrecedent $p): string => $p->noteId,
            $precedents,
        ));
    }

    public function testDirectConstructionRejectsAnEmptyIdentity(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RenderedLearningPrecedent(' ', null, null, null, []);
    }

    public function testUnavailableLearningOwnerPreviewsNothing(): void
    {
        $preview = new LearningPrecedentPreview($this->provider([$this->note('learning-note.gate', ['src/'])], available: false));

        self::assertSame([], $preview->forCandidateScope($this->root, 'REL-003', ['src/Foo.php']));
    }

    public function testMissingEvidenceFailsClosedLikeCompilation(): void
    {
        $preview = new LearningPrecedentPreview($this->provider([$this->note('learning-note.gone', ['src/'], 'source_missing')]));

        $this->expectException(RecallCompilationBlockedException::class);
        $preview->forCandidateScope($this->root, 'REL-003', ['src/Foo.php']);
    }

    /** @param list<LearningNotePrecedentProjection> $notes */
    private function provider(array $notes, bool $available = true): LearningNoteRecallProvider
    {
        return new LearningNoteRecallProvider(new readonly class($notes, $available) implements LearningNoteProjectionSource {
            /** @param list<LearningNotePrecedentProjection> $notes */
            public function __construct(private array $notes, private bool $available)
            {
            }

            public function isAvailable(): bool
            {
                return $this->available;
            }

            public function forTask(string $learningRoot, string $taskId, ?string $projectRoot = null): LearningTaskPrecedentProjection
            {
                $ids = array_map(static fn (LearningNotePrecedentProjection $note): string => $note->id, $this->notes);

                return new LearningTaskPrecedentProjection(
                    taskId: $taskId,
                    precedents: $this->notes,
                    identityIds: $ids,
                    depthByIdentityId: [$taskId => 0, ...array_fill_keys($ids, 1)],
                    relations: [],
                    maximumDepth: 3,
                    maximumResults: 100,
                    truncated: false,
                );
            }
        });
    }

    /** @param list<string> $scope */
    private function note(string $id, array $scope, string $state = 'current'): LearningNotePrecedentProjection
    {
        return new LearningNotePrecedentProjection(
            id: $id,
            patternKey: 'pattern.' . $id,
            scope: $scope,
            tags: [],
            sourceFindings: ['finding.' . $id],
            sourceProposals: [],
            content: ['title' => 'Title ' . $id, 'guidance' => 'Prior guidance for ' . $id],
            digest: hash('sha256', $id),
            evidenceState: $state,
        );
    }
}
