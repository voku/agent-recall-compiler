<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentRecallCompiler\Output\CompiledRecallOutputReader;

/** @internal */
final class CompiledRecallOutputReaderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/recall-output-' . bin2hex(random_bytes(6));
        if (!mkdir($this->dir, 0o775, true) && !is_dir($this->dir)) {
            throw new RuntimeException('Unable to create fixture directory.');
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    public function testAbsentOutputIsReportedAsAbsentRatherThanEmpty(): void
    {
        self::assertNull((new CompiledRecallOutputReader())->read($this->dir));
    }

    public function testCurrentOutputAnswersIdentityAndSelectionQuestions(): void
    {
        $this->writeMeta(['compilation_id' => 'c-1', 'bundle_sha256' => 'sha256:' . str_repeat('a', 64)]);
        $this->writeBundle('ABC-123', 2);

        $output = (new CompiledRecallOutputReader())->read($this->dir);

        self::assertNotNull($output);
        self::assertSame('c-1', $output->compilationId());
        self::assertTrue($output->bindsTo('ABC-123', 2));
        self::assertFalse($output->isBlocked());
        self::assertTrue($output->isComplete());
        self::assertSame(['g-1'], $output->selectedGuidance());
        self::assertSame(['c-1'], $output->selectedConstraints());
    }

    public function testMetadataNamingAnotherTaskIsDetectedSeparatelyFromBinding(): void
    {
        $this->writeMeta(['task_id' => 'OTHER-9']);
        $this->writeBundle('OTHER-9', 1);

        $output = (new CompiledRecallOutputReader())->read($this->dir);

        self::assertNotNull($output);
        self::assertFalse($output->describesTask('ABC-123'));
        self::assertTrue($output->describesTask('OTHER-9'));
    }

    public function testOutputCompiledForAnotherRevisionDoesNotBind(): void
    {
        $this->writeMeta();
        $this->writeBundle('ABC-123', 1);

        $output = (new CompiledRecallOutputReader())->read($this->dir);

        self::assertNotNull($output);
        self::assertTrue($output->bindsTo('ABC-123', 1));
        self::assertFalse($output->bindsTo('ABC-123', 2), 'A newer Contract revision must not accept older output.');
        self::assertFalse($output->bindsTo('OTHER-1', 1), 'Another task must not accept this output.');
    }

    public function testSnapshotWithoutBundleIsIncompleteNotMerelyUnbound(): void
    {
        $this->writeMeta(['snapshot_sha256' => 'sha256:' . str_repeat('b', 64)]);

        $output = (new CompiledRecallOutputReader())->read($this->dir);

        self::assertNotNull($output);
        self::assertFalse($output->isComplete());
        self::assertFalse($output->bindsTo('ABC-123', 1));
    }

    public function testBlockedCompilationReportsItsReason(): void
    {
        $this->writeMeta(['blocked' => true, 'block_reason' => 'no guidance available']);

        $output = (new CompiledRecallOutputReader())->read($this->dir);

        self::assertNotNull($output);
        self::assertTrue($output->isBlocked());
        self::assertSame('no guidance available', $output->blockReason());
    }

    public function testFactsAreReturnedTypedAndSkipMalformedRows(): void
    {
        $this->writeMeta();
        file_put_contents($this->dir . '/facts.json', json_encode([
            'facts' => [
                ['type' => 'kanban', 'payload' => ['card' => 'ABC-123']],
                ['payload' => ['no' => 'type']],
                ['type' => 'navigation', 'payload' => []],
            ],
        ], JSON_THROW_ON_ERROR));

        $facts = (new CompiledRecallOutputReader())->read($this->dir)?->facts() ?? [];

        self::assertCount(2, $facts);
        self::assertSame('kanban', $facts[0]->type);
        self::assertSame(['card' => 'ABC-123'], $facts[0]->payload);
        self::assertSame('navigation', $facts[1]->type);
    }

    public function testUnreadableOutputFailsLoudlyInsteadOfLookingEmpty(): void
    {
        file_put_contents($this->dir . '/meta.json', '{"compilation_id":');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid Recall output JSON');

        (new CompiledRecallOutputReader())->read($this->dir);
    }

    /** @param array<string, mixed> $overrides */
    private function writeMeta(array $overrides = []): void
    {
        file_put_contents($this->dir . '/meta.json', json_encode($overrides + [
            'schema_version' => '1.0',
            'task_id' => 'ABC-123',
            'compilation_id' => 'c-1',
            'selected_guidance' => ['g-1'],
            'selected_constraints' => ['c-1'],
        ], JSON_THROW_ON_ERROR));
    }

    private function writeBundle(string $taskId, int $revision): void
    {
        file_put_contents($this->dir . '/recall.bundle.json', json_encode([
            'task' => ['id' => $taskId, 'revision' => $revision],
        ], JSON_THROW_ON_ERROR));
    }
}
