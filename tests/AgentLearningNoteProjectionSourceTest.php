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
use voku\AgentLearning\ValidationCase;
use voku\AgentRecallCompiler\Provider\AgentLearningNoteProjectionSource;

final class AgentLearningNoteProjectionSourceTest extends TestCase
{
    public function testMapsReleasedPublicOwnerProjectionWithoutPrivateStorageKnowledge(): void
    {
        self::assertSame('0.14.0', InstalledVersions::getPrettyVersion('voku/agent-learning'));
        self::assertTrue((new AgentLearningNoteProjectionSource())->isAvailable());

        $source = new AgentLearningNoteProjectionSource(ReleasedLearningNoteService::class);
        $notes = $source->active('/tmp/learning');

        self::assertCount(1, $notes);
        self::assertSame('learning-note.real', $notes[0]->id);
        self::assertSame('pattern.real', $notes[0]->patternKey);
        self::assertSame(['src/'], $notes[0]->scope);
        self::assertSame('current', $notes[0]->evidenceState);
        self::assertSame('Real owner projection', $notes[0]->content['title']);
    }

    public function testMissingOptionalOwnerPackageIsEmptyCapability(): void
    {
        $source = new AgentLearningNoteProjectionSource('voku\\AgentRecallCompiler\\Tests\\DefinitelyMissingLearningService');

        self::assertSame([], $source->active('/tmp/learning'));
    }

    public function testMalformedConfiguredOwnerProjectionFailsExplicitly(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('requires canonical SHA-256 digest');

        (new AgentLearningNoteProjectionSource(MalformedLearningNoteService::class))->active('/tmp/learning');
    }
}

final class ReleasedLearningNoteService
{
    /** @return list<LearningNoteProjection> */
    public function activeProjections(string $learningRoot): array
    {
        return [ReleasedLearningNoteProjectionFixture::create(str_repeat('a', 64))];
    }
}

final class MalformedLearningNoteService
{
    /** @return list<LearningNoteProjection> */
    public function activeProjections(string $learningRoot): array
    {
        return [ReleasedLearningNoteProjectionFixture::create('not-a-digest')];
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
