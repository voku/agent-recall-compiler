<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentRecallCompiler\CompileRequest;
use voku\AgentRecallCompiler\KanbanContextProjection;
use voku\AgentRecallCompiler\RecallCompiler;

final class KanbanContextProjectionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/recall-kanban-projection-' . bin2hex(random_bytes(6));
        foreach (['proposals/approved', 'proposals/applied', 'proposals/rejected', 'constraints/active', 'history'] as $path) {
            mkdir($this->root . '/learning/' . $path, 0o775, true);
        }
        file_put_contents($this->root . '/task.json', json_encode([
            'schema_version' => '1.0',
            'id' => 'PUBLIC-KANBAN-1',
            'description' => 'Compile bounded board facts without a persisted orchestration context file.',
            'files' => ['src/Example.php'],
        ], JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->root)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    public function testEmbeddedCompileConsumesTypedProjectionWithoutContextFile(): void
    {
        $output = $this->root . '/output';
        (new RecallCompiler())->compile(new CompileRequest(
            learningRoot: $this->root . '/learning',
            taskBrief: $this->root . '/task.json',
            outputDirectory: $output,
            kanbanContextProjection: $this->projection(),
            compilationId: 'compilation.PUBLIC-KANBAN-1.projection',
        ));

        self::assertFileDoesNotExist($output . '/kanban-context.json');

        $facts = json_decode((string) file_get_contents($output . '/facts.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($facts);
        $kanbanFacts = array_values(array_filter(
            $facts['facts'],
            static fn (array $fact): bool => ($fact['id'] ?? null) === 'kanban.PUBLIC-KANBAN-1',
        ));
        self::assertCount(1, $kanbanFacts);
        self::assertSame('kanban_board', $kanbanFacts[0]['authority']);
        self::assertSame('todo/cards/PUBLIC-KANBAN-1.md', $kanbanFacts[0]['source_ref']);
        self::assertSame('sha256:card-revision', $kanbanFacts[0]['payload']['source_revision']);
        self::assertSame([
            'lane' => 'READY',
            'next_action' => 'Compile the bounded board facts.',
            'priority' => 1,
            'status' => 'Selected',
            'title' => 'Keep the Recall projection bounded',
        ], $kanbanFacts[0]['payload']['card']);

        $bundle = json_decode((string) file_get_contents($output . '/recall.bundle.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($bundle);
        $providers = array_values(array_filter(
            $bundle['snapshot']['providers'],
            static fn (array $provider): bool => ($provider['manifest']['id'] ?? null) === 'kanban-context',
        ));
        self::assertCount(1, $providers);
        self::assertSame(['todo/cards/PUBLIC-KANBAN-1.md'], $providers[0]['manifest']['source_paths']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $providers[0]['source_digest']);
    }

    public function testCompileRequestRejectsPathAndTypedProjectionTogether(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('kanbanContext and kanbanContextProjection are mutually exclusive.');

        new CompileRequest(
            learningRoot: $this->root . '/learning',
            taskBrief: $this->root . '/task.json',
            outputDirectory: $this->root . '/output',
            kanbanContext: $this->root . '/legacy-kanban-context.json',
            kanbanContextProjection: $this->projection(),
        );
    }

    private function projection(): KanbanContextProjection
    {
        return new KanbanContextProjection(
            taskId: 'PUBLIC-KANBAN-1',
            sourcePath: 'todo/cards/PUBLIC-KANBAN-1.md',
            sourceRevision: 'sha256:card-revision',
            title: 'Keep the Recall projection bounded',
            lane: 'READY',
            status: 'Selected',
            priority: 1,
            nextAction: 'Compile the bounded board facts.',
        );
    }
}
