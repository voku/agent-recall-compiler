<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentRecallCompiler\Provider\TaskContextRecallProvider;
use voku\AgentRecallCompiler\RecallRootConfig;
use voku\AgentRecallCompiler\TaskBrief;
use voku\AgentRecallCompiler\TaskBriefParser;

final class GovernedRunBindingTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-recall-governed-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testGovernedEnvelopeBindsExactApprovedContract(): void
    {
        $contractPath = $this->writeContract();
        $brief = (new TaskBriefParser())->parseFile($this->writeEnvelope(
            'run:ABC-123:one',
            $contractPath,
            2,
        ));

        self::assertSame('ABC-123', $brief->id);
        self::assertSame('Keep parser behavior reviewable.', $brief->description);
        self::assertSame(['src/Parser.php'], $brief->files);
        self::assertSame(['composer ci'], $brief->validation);
        self::assertNotNull($brief->governedRun);
        self::assertSame('run:ABC-123:one', $brief->governedRun->runId);
        self::assertSame(2, $brief->governedRun->contractRevision);
        self::assertSame('contract.json', $brief->governedRun->contractSource);
        self::assertSame('sha256:' . hash_file('sha256', $contractPath), $brief->governedRun->contractSha256);

        $fact = (new TaskContextRecallProvider())->collect(
            $brief,
            new RecallRootConfig($this->root, $this->root . '/constraints/active', $this->root),
        )->facts[0];
        self::assertSame('approved_contract_bound_to_governed_run', $fact->evidenceLabel);
        self::assertSame('run:ABC-123:one', $fact->payload['governed_run']['run_id'] ?? null);
    }

    public function testChangedRunIdChangesLineageOnly(): void
    {
        $contractPath = $this->writeContract();
        $first = (new TaskBriefParser())->parseFile($this->writeEnvelope('run:ABC-123:one', $contractPath, 2, 'one.json'));
        $second = (new TaskBriefParser())->parseFile($this->writeEnvelope('run:ABC-123:two', $contractPath, 2, 'two.json'));

        self::assertSame($this->taskSemantics($first), $this->taskSemantics($second));
        self::assertNotSame($first->governedRun?->runId, $second->governedRun?->runId);
        self::assertSame($first->governedRun?->contractSha256, $second->governedRun?->contractSha256);
    }

    public function testDigestMismatchFailsClosed(): void
    {
        $contractPath = $this->writeContract();
        $envelope = $this->envelopeData('run:ABC-123:one', $contractPath, 2);
        $envelope['contract']['sha256'] = 'sha256:' . str_repeat('0', 64);
        $path = $this->root . '/governed.json';
        file_put_contents($path, json_encode($envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Contract digest does not match');
        (new TaskBriefParser())->parseFile($path);
    }

    public function testRevisionMismatchFailsClosed(): void
    {
        $contractPath = $this->writeContract();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Contract revision does not match');
        (new TaskBriefParser())->parseFile($this->writeEnvelope('run:ABC-123:one', $contractPath, 3));
    }

    public function testUnapprovedContractCannotDriveGovernedRecall(): void
    {
        $contractPath = $this->writeContract('candidate');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('requires an approved Contract');
        (new TaskBriefParser())->parseFile($this->writeEnvelope('run:ABC-123:one', $contractPath, 2));
    }

    private function writeContract(string $status = 'approved'): string
    {
        $path = $this->root . '/contract.json';
        file_put_contents($path, json_encode([
            'schema_version' => '1.0',
            'kind' => 'task_contract',
            'task_id' => 'ABC-123',
            'goal' => 'Keep parser behavior reviewable.',
            'scope' => ['src/Parser.php'],
            'non_goals' => ['Do not change the public parser API.'],
            'validation' => ['composer ci'],
            'tags' => ['parser'],
            'behavior_anchors' => ['source -> parser -> AST'],
            'status' => $status,
            'revision' => 2,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $path;
    }

    private function writeEnvelope(string $runId, string $contractPath, int $revision, string $name = 'governed.json'): string
    {
        $path = $this->root . '/' . $name;
        file_put_contents(
            $path,
            json_encode($this->envelopeData($runId, $contractPath, $revision), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );

        return $path;
    }

    /** @return array<string, mixed> */
    private function envelopeData(string $runId, string $contractPath, int $revision): array
    {
        return [
            'schema_version' => '1.0',
            'kind' => 'governed_recall_input',
            'run_id' => $runId,
            'contract' => [
                'path' => basename($contractPath),
                'sha256' => 'sha256:' . hash_file('sha256', $contractPath),
                'revision' => $revision,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function taskSemantics(TaskBrief $brief): array
    {
        return [
            'id' => $brief->id,
            'description' => $brief->description,
            'files' => $brief->files,
            'scopes' => $brief->scopes,
            'non_goals' => $brief->nonGoals,
            'validation' => $brief->validation,
            'status' => $brief->status,
            'revision' => $brief->revision,
            'tags' => $brief->tags,
            'behavior_anchors' => $brief->behaviorAnchors,
            'targets' => $brief->targets,
        ];
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
