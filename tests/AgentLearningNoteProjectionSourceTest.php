<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentRecallCompiler\Provider\AgentLearningNoteProjectionSource;

final class AgentLearningNoteProjectionSourceTest extends TestCase
{
    public function testMapsPublicOwnerProjectionWithoutPrivateStorageKnowledge(): void
    {
        $source = new AgentLearningNoteProjectionSource(FakeLearningNoteService::class);
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

final class FakeLearningNoteService
{
    /** @return list<FakeLearningNoteProjection> */
    public function activeProjections(string $learningRoot): array
    {
        return [new FakeLearningNoteProjection(str_repeat('a', 64))];
    }
}

final class MalformedLearningNoteService
{
    /** @return list<FakeLearningNoteProjection> */
    public function activeProjections(string $learningRoot): array
    {
        return [new FakeLearningNoteProjection('not-a-digest')];
    }
}

final readonly class FakeLearningNoteProjection
{
    public function __construct(private string $digest)
    {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => 'learning-note.real',
            'pattern_key' => 'pattern.real',
            'status' => 'active',
            'scope' => ['src/'],
            'tags' => ['architecture'],
            'source_findings' => ['finding.real.001'],
            'source_proposals' => [],
            'validation_case' => [
                'given' => 'A relevant task.',
                'when' => 'The prior case applies.',
                'then' => 'The precedent is available.',
            ],
            'content' => [
                'title' => 'Real owner projection',
                'context' => 'Historical context.',
                'guidance' => 'Prior guidance.',
                'why_it_works' => 'Reason.',
                'when_to_apply' => 'When relevant.',
                'when_not_to_apply' => 'When stronger evidence differs.',
                'verification' => 'Verify current source.',
                'symptoms' => null,
                'failed_approaches' => [],
                'root_cause' => null,
                'examples' => [],
            ],
            'digest' => $this->digest,
            'evidence_state' => 'current',
        ];
    }
}
