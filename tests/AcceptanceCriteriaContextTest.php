<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentRecallCompiler\Compilation\TaskScopeResolver;
use voku\AgentRecallCompiler\Provider\TaskContextRecallProvider;
use voku\AgentRecallCompiler\RecallPromptBuilder;
use voku\AgentRecallCompiler\RecallResult;
use voku\AgentRecallCompiler\RecallRootConfig;
use voku\AgentRecallCompiler\TaskBrief;
use voku\AgentRecallCompiler\TaskBriefParser;

final class AcceptanceCriteriaContextTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-recall-acceptance-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->root, 0o775, true));
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

    public function testDirectTaskBriefPreservesCriteriaAndMissingFieldDefaultsToEmpty(): void
    {
        $path = $this->root . '/task.json';
        $this->writeJson($path, [
            'schema_version' => '1.0',
            'task_id' => 'TASK-1',
            'goal' => 'Keep required outcomes visible.',
            'scope' => ['src/'],
            'validation' => ['composer ci'],
            'acceptance_criteria' => ['first required outcome', 'second required outcome', 'first required outcome'],
        ]);

        $brief = (new TaskBriefParser())->parseFile($path);
        self::assertSame(['first required outcome', 'second required outcome'], $brief->acceptanceCriteria);

        $this->writeJson($path, [
            'schema_version' => '1.0',
            'task_id' => 'TASK-1',
            'scope' => ['src/'],
            'validation' => ['composer ci'],
        ]);
        self::assertSame([], (new TaskBriefParser())->parseFile($path)->acceptanceCriteria);
    }

    public function testParserRejectsMalformedAcceptanceCriteria(): void
    {
        $path = $this->root . '/task.json';
        $this->writeJson($path, [
            'schema_version' => '1.0',
            'task_id' => 'TASK-1',
            'acceptance_criteria' => ['valid', 123],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('task acceptance_criteria must contain only non-empty strings');

        (new TaskBriefParser())->parseFile($path);
    }

    public function testGovernedContractEnvelopePreservesAcceptanceCriteria(): void
    {
        $contractDirectory = $this->root . '/contracts/TASK-1';
        $runDirectory = $this->root . '/runs/TASK-1';
        self::assertTrue(mkdir($contractDirectory, 0o775, true));
        self::assertTrue(mkdir($runDirectory, 0o775, true));

        $contractPath = $contractDirectory . '/contract.json';
        $this->writeJson($contractPath, [
            'schema_version' => '1.0',
            'kind' => 'task_contract',
            'task_id' => 'TASK-1',
            'goal' => 'Keep required outcomes visible.',
            'scope' => ['src/'],
            'non_goals' => [],
            'validation' => ['composer ci'],
            'acceptance_criteria' => ['Recall must expose this exact criterion.'],
            'tags' => [],
            'behavior_anchors' => [],
            'operating_prompts' => [],
            'status' => 'approved',
            'revision' => 2,
            'planned_by' => 'agent',
            'base_commit' => null,
            'approved_by' => 'human',
            'approved_at' => '2026-08-13T07:00:00+00:00',
            'created_at' => '2026-08-13T06:00:00+00:00',
            'updated_at' => '2026-08-13T07:00:00+00:00',
        ]);
        $hash = hash_file('sha256', $contractPath);
        self::assertIsString($hash);

        $envelopePath = $runDirectory . '/recall-input.json';
        $this->writeJson($envelopePath, [
            'schema_version' => '1.0',
            'kind' => 'governed_recall_input',
            'run_id' => 'run-task-1',
            'contract' => [
                'path' => '../../contracts/TASK-1/contract.json',
                'sha256' => 'sha256:' . $hash,
                'revision' => 2,
            ],
        ]);

        $brief = (new TaskBriefParser())->parseFile($envelopePath);
        self::assertSame(['Recall must expose this exact criterion.'], $brief->acceptanceCriteria);
        self::assertSame('run-task-1', $brief->governedRun?->runId);
    }

    public function testFactsSystemPromptAndEffectiveTaskKeepCriteriaWithoutClaimingSatisfaction(): void
    {
        $task = new TaskBrief(
            id: 'TASK-1',
            description: 'Keep required outcomes visible.',
            files: ['src/Example.php'],
            acceptanceCriteria: [
                'installed skill exposes the new behavior',
                'workflow report preserves this requirement',
            ],
        );

        $facts = (new TaskContextRecallProvider())->collect(
            $task,
            new RecallRootConfig($this->root, $this->root . '/constraints'),
        )->facts;
        self::assertSame($task->acceptanceCriteria, $facts[0]->payload['acceptance_criteria'] ?? null);

        $prompt = (new RecallPromptBuilder())->buildSystemMd($task, '', new RecallResult([], [], []));
        self::assertStringContainsString('## Acceptance Criteria', $prompt);
        self::assertStringContainsString('required outcomes from the approved task Contract', $prompt);
        self::assertStringContainsString('not evidence that they are satisfied', $prompt);
        self::assertStringContainsString('installed skill exposes the new behavior', $prompt);
        self::assertStringContainsString('workflow report preserves this requirement', $prompt);

        $effective = (new TaskScopeResolver())->resolve($task, [])->effectiveTask;
        self::assertSame($task->acceptanceCriteria, $effective->acceptanceCriteria);
    }

    /** @param array<string, mixed> $data */
    private function writeJson(string $path, array $data): void
    {
        self::assertNotFalse(file_put_contents(
            $path,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ));
    }
}
