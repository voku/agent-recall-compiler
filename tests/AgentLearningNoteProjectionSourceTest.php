<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use Composer\InstalledVersions;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentLearning\LearningNoteContent;
use voku\AgentLearning\LearningNoteEvidenceState;
use voku\AgentLearning\LearningNoteProjection;
use voku\AgentLearning\LearningNoteStatus;
use voku\AgentLearning\Lineage\LearningLineageResult;
use voku\AgentLearning\Lineage\LearningTaskPrecedentResult;
use voku\AgentLearning\ValidationCase;
use voku\AgentRecallCompiler\Provider\AgentLearningNoteProjectionSource;

final class AgentLearningNoteProjectionSourceTest extends TestCase
{
    public function testMapsReleasedBoundedOwnerProjectionWithoutPrivateStorageKnowledge(): void
    {
        $learningVersion = InstalledVersions::getPrettyVersion('voku/agent-learning');
        self::assertContains($learningVersion, ['0.18.1', '0.18.2', '0.18.3']);
        $expectedPrecedentsTruncated = $learningVersion === '0.18.3' ? false : null;

        self::assertTrue((new AgentLearningNoteProjectionSource())->isAvailable());

        $selection = (new AgentLearningNoteProjectionSource(ReleasedLearningLineageService::class))->forTask(
            '/tmp/learning',
            'TASK-123',
        );

        self::assertSame('TASK-123', $selection->taskId);
        self::assertCount(1, $selection->precedents);
        self::assertSame('learning-note.real', $selection->precedents[0]->id);
        self::assertSame('pattern.real', $selection->precedents[0]->patternKey);
        self::assertSame(['src/'], $selection->precedents[0]->scope);
        self::assertSame('current', $selection->precedents[0]->evidenceState);
        self::assertSame('Real owner projection', $selection->precedents[0]->content['title']);
        self::assertSame(['finding.real.001', 'learning-note.real'], $selection->identityIds);
        self::assertSame(3, $selection->maximumDepth);
        self::assertSame(100, $selection->maximumResults);
        self::assertTrue($selection->truncated);
        self::assertSame($expectedPrecedentsTruncated, $selection->precedentsTruncated);
        self::assertArrayHasKey('precedents_truncated', $selection->observation());
        self::assertSame($expectedPrecedentsTruncated, $selection->observation()['precedents_truncated']);
    }

    public function testPreservesOwnerReportedPrecedentTruncationSeparatelyFromLineageTruncation(): void
    {
        $selection = (new AgentLearningNoteProjectionSource(ReportedTruncationLearningLineageService::class))->forTask(
            '/tmp/learning',
            'TASK-123',
        );

        self::assertFalse($selection->truncated);
        self::assertTrue($selection->precedentsTruncated);
        self::assertFalse($selection->observation()['truncated']);
        self::assertTrue($selection->observation()['precedents_truncated']);
    }

    public function testMalformedOwnerPrecedentTruncationFailsExplicitly(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('requires boolean precedents_truncated');

        (new AgentLearningNoteProjectionSource(MalformedTruncationLearningLineageService::class))->forTask(
            '/tmp/learning',
            'TASK-123',
        );
    }

    public function testMissingOptionalOwnerPackageIsUnavailableCapability(): void
    {
        $source = new AgentLearningNoteProjectionSource('voku\\AgentRecallCompiler\\Tests\\DefinitelyMissingLearningService');

        self::assertFalse($source->isAvailable());
    }

    public function testMismatchedOwnerTaskBindingFailsExplicitly(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('bound to a different task id');

        (new AgentLearningNoteProjectionSource(MismatchedLearningLineageService::class))->forTask(
            '/tmp/learning',
            'TASK-123',
        );
    }

    public function testOwnerFailureOrStaleStatePropagatesExplicitly(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Learning state changed during task precedent query; retry from one owner generation.');

        (new AgentLearningNoteProjectionSource(StaleOwnerLearningLineageService::class))->forTask(
            '/tmp/learning',
            'TASK-123',
        );
    }

    public function testMalformedConfiguredOwnerProjectionFailsExplicitly(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('requires canonical SHA-256 digest');

        (new AgentLearningNoteProjectionSource(MalformedLearningLineageService::class))->forTask(
            '/tmp/learning',
            'TASK-123',
        );
    }
}

final class StaleOwnerLearningLineageService
{
    public function precedentsForTask(
        string $learningRoot,
        string $taskId,
        ?string $projectRoot = null,
    ): LearningTaskPrecedentResult {
        throw new RuntimeException('Learning state changed during task precedent query; retry from one owner generation.');
    }
}

final class ReleasedLearningLineageService
{
    public function precedentsForTask(
        string $learningRoot,
        string $taskId,
        ?string $projectRoot = null,
    ): LearningTaskPrecedentResult {
        return ReleasedLearningTaskPrecedentFixture::create($taskId, str_repeat('a', 64));
    }
}

final class ReportedTruncationLearningLineageService
{
    public function precedentsForTask(
        string $learningRoot,
        string $taskId,
        ?string $projectRoot = null,
    ): ReportedTruncationLearningTaskPrecedentResult {
        return new ReportedTruncationLearningTaskPrecedentResult($taskId, true);
    }
}

final class MalformedTruncationLearningLineageService
{
    public function precedentsForTask(
        string $learningRoot,
        string $taskId,
        ?string $projectRoot = null,
    ): ReportedTruncationLearningTaskPrecedentResult {
        return new ReportedTruncationLearningTaskPrecedentResult($taskId, 'yes');
    }
}

final class MismatchedLearningLineageService
{
    public function precedentsForTask(
        string $learningRoot,
        string $taskId,
        ?string $projectRoot = null,
    ): LearningTaskPrecedentResult {
        return ReleasedLearningTaskPrecedentFixture::create('OTHER-999', str_repeat('a', 64));
    }
}

final class MalformedLearningLineageService
{
    public function precedentsForTask(
        string $learningRoot,
        string $taskId,
        ?string $projectRoot = null,
    ): LearningTaskPrecedentResult {
        return ReleasedLearningTaskPrecedentFixture::create($taskId, 'not-a-digest');
    }
}

final readonly class ReportedTruncationLearningTaskPrecedentResult
{
    public function __construct(
        private string $taskId,
        private mixed $precedentsTruncated,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            ...ReleasedLearningTaskPrecedentFixture::create($this->taskId, str_repeat('a', 64))->toArray(),
            'lineage' => [
                ...ReleasedLearningTaskPrecedentFixture::create($this->taskId, str_repeat('a', 64))->toArray()['lineage'],
                'truncated' => false,
            ],
            'precedents_truncated' => $this->precedentsTruncated,
        ];
    }
}

final class ReleasedLearningTaskPrecedentFixture
{
    public static function create(string $taskId, string $digest): LearningTaskPrecedentResult
    {
        return new LearningTaskPrecedentResult(
            taskId: $taskId,
            precedents: [ReleasedLearningNoteProjectionFixture::create($digest)],
            lineage: new LearningLineageResult(
                identityId: $taskId,
                identityIds: ['finding.real.001', 'learning-note.real'],
                depthByIdentityId: [
                    $taskId => 0,
                    'finding.real.001' => 1,
                    'learning-note.real' => 2,
                ],
                relations: [],
                maximumDepth: 3,
                maximumResults: 100,
                truncated: true,
            ),
        );
    }
}

final class ReleasedLearningNoteProjectionFixture
{
    public static function create(string $digest): LearningNoteProjection
    {
        return new LearningNoteProjection(
            id: 'learning-note.real',
            patternKey: 'pattern.real',
            status: LearningNoteStatus::ACTIVE,
            scope: ['src/'],
            tags: ['architecture'],
            sourceFindings: ['finding.real.001'],
            sourceProposals: [],
            validationCase: new ValidationCase(
                given: 'A relevant task.',
                when: 'The prior case applies.',
                then: 'The precedent is available.',
            ),
            content: new LearningNoteContent(
                title: 'Real owner projection',
                context: 'Historical context.',
                guidance: 'Prior guidance.',
                whyItWorks: 'Reason.',
                whenToApply: 'When relevant.',
                whenNotToApply: 'When stronger evidence differs.',
                verification: 'Verify current source.',
            ),
            digest: $digest,
            evidenceState: LearningNoteEvidenceState::CURRENT,
        );
    }
}
