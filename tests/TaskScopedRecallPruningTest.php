<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentRecallCompiler\Cli;
use voku\AgentRecallCompiler\EvaluatedGuidance;
use voku\AgentRecallCompiler\ExclusionReason;
use voku\AgentRecallCompiler\GuidanceType;
use voku\AgentRecallCompiler\OutcomeLogger;
use voku\AgentRecallCompiler\Output\RecallFactsDocumentReader;
use voku\AgentRecallCompiler\Provider\MemoryRecallProvider;
use voku\AgentRecallCompiler\RecallRootConfig;
use voku\AgentRecallCompiler\SelectionReason;
use voku\AgentRecallCompiler\TaskBrief;

final class TaskScopedRecallPruningTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/task-scoped-pruning-test-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testLargeMemoryIsPrunedToRequiredAndRelevantSectionsForTask(): void
    {
        $memoryPath = $this->root . '/MEMORY.md';
        $memory = "# Global Project Memory\n\n";
        $memory .= "## Core Invariants & Never Rules\n";
        $memory .= "1. Never bypass security boundaries.\n2. Never execute unverified dynamic SQL.\n\n";
        $memory .= "## Architecture Baseline\n";
        $memory .= "Strict MVC and PSR Container.\n\n";
        $memory .= "## Domain Section: Authentication and Passwords\n";
        $memory .= "Use secure password hashing with bcrypt cost 12.\n" . str_repeat("Auth security guidelines and token policies.\n", 100);
        $memory .= "\n## Domain Section: Billing and Payments\n";
        $memory .= "Billing calculations must use bcmath.\n" . str_repeat("Billing payment gateway integration rules.\n", 200);
        $memory .= "\n## Domain Section: Email Templates\n";
        $memory .= "Render templates via Smarty with escaping.\n" . str_repeat("Email delivery queues and retry limits.\n", 200);

        file_put_contents($memoryPath, $memory);
        self::assertGreaterThan(12000, strlen($memory));

        $task = new TaskBrief(
            'AUTH-01',
            'Fix password validator bounds',
            ['src/Auth/PasswordValidator.php'],
            tags: ['auth', 'security'],
        );

        $provider = new MemoryRecallProvider();
        $config = new RecallRootConfig($this->root, 'constraints');
        $result = $provider->collect($task, $config);

        self::assertCount(1, $result->facts);
        $prunedContent = $result->facts[0]->payload['content'];

        // Required sections preserved
        self::assertStringContainsString('Global Project Memory', $prunedContent);
        self::assertStringContainsString('Core Invariants & Never Rules', $prunedContent);
        self::assertStringContainsString('Architecture Baseline', $prunedContent);

        // Relevant section preserved
        self::assertStringContainsString('Domain Section: Authentication and Passwords', $prunedContent);

        // Irrelevant sections pruned
        self::assertStringNotContainsString('Domain Section: Billing and Payments', $prunedContent);
        self::assertStringNotContainsString('Domain Section: Email Templates', $prunedContent);

        // Substantial size reduction verified
        self::assertLessThan(strlen($memory) / 2, strlen($prunedContent));
    }

    public function testSmallMemoryIsPreservedUnchanged(): void
    {
        $smallMemory = "# Small Memory\n\n## Section 1\nSome rule.\n## Section 2\nAnother rule.\n";
        file_put_contents($this->root . '/MEMORY.md', $smallMemory);

        $task = new TaskBrief('TASK-1', 'Goal', ['src/Foo.php']);
        $result = (new MemoryRecallProvider())->collect($task, new RecallRootConfig($this->root, 'constraints'));

        self::assertSame(trim($smallMemory), $result->facts[0]->payload['content']);
    }

    public function testUntrackedTaskPreservesAllMemorySections(): void
    {
        $memory = "# Large Memory\n\n" . str_repeat("## Section\nContent line.\n\n", 400);
        file_put_contents($this->root . '/MEMORY.md', $memory);

        $task = new TaskBrief('UNTRACKED-1', 'Open-ended task without files or tags', []);
        $result = (new MemoryRecallProvider())->collect($task, new RecallRootConfig($this->root, 'constraints'));

        self::assertSame(trim($memory), $result->facts[0]->payload['content']);
    }

    public function testBundleFactsAreCompactWhileFactsDocumentPreservesFullPayloads(): void
    {
        $learningRoot = $this->root . '/learning';
        mkdir($learningRoot . '/constraints/active', 0777, true);
        mkdir($learningRoot . '/history', 0777, true);

        file_put_contents($learningRoot . '/config.json', json_encode([
            'schema_version' => '1.0',
            'project_root' => '..',
        ], JSON_THROW_ON_ERROR));

        file_put_contents($this->root . '/MEMORY.md', "# Project Memory\n\nDurable rule.\n");

        file_put_contents($learningRoot . '/constraints/active/constraint.auth.json', json_encode([
            'schema_version' => '1.0',
            'id' => 'constraint.auth',
            'engine' => 'phpstan',
            'rule_identifier' => 'auth.noSuperglobals',
            'scope' => ['src/Auth/'],
            'validation_commands' => ['vendor/bin/phpstan analyse src/Auth/'],
            'source_proposal' => 'proposal.2026-06-01.001',
            'status' => 'active',
        ], JSON_THROW_ON_ERROR));

        $briefPath = $this->root . '/brief.json';
        file_put_contents($briefPath, json_encode([
            'schema_version' => '1.0',
            'task_id' => 'TASK-PRUNE-1',
            'goal' => 'Validate bundle and fact documents',
            'scope' => ['src/Auth/Login.php'],
            'validation' => ['vendor/bin/phpstan analyse src/Auth/'],
            'status' => 'approved',
            'revision' => 1,
        ], JSON_THROW_ON_ERROR));

        $outputDir = $this->root . '/output';
        $cli = new Cli();
        $exitCode = $cli->run([
            'agent-recall-compiler', 'compile',
            '--root', $learningRoot,
            '--task', 'TASK-PRUNE-1',
            '--task-brief', $briefPath,
            '--output-dir', $outputDir,
            '--compilation-id', 'compilation.PRUNE-1.test',
        ]);

        self::assertSame(0, $exitCode);

        // Bundle facts are compact descriptors (no payload duplication)
        $bundle = json_decode((string) file_get_contents($outputDir . '/recall.bundle.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($bundle['facts']);
        self::assertNotEmpty($bundle['facts']);
        foreach ($bundle['facts'] as $bundleFact) {
            self::assertArrayHasKey('id', $bundleFact);
            self::assertArrayHasKey('type', $bundleFact);
            self::assertArrayHasKey('authority', $bundleFact);
            self::assertArrayNotHasKey('payload', $bundleFact);
        }

        // facts.json retains the full payload for tools that need it
        $factsDoc = (new RecallFactsDocumentReader())->read($outputDir . '/facts.json');
        self::assertNotNull($factsDoc);
        self::assertNotEmpty($factsDoc->facts);
        $memoryFact = null;
        foreach ($factsDoc->facts as $fact) {
            if ($fact->type === 'memory') {
                $memoryFact = $fact;
                break;
            }
        }
        self::assertNotNull($memoryFact);
        self::assertArrayHasKey('content', $memoryFact->payload);
        self::assertStringContainsString('Durable rule.', $memoryFact->payload['content']);
    }

    public function testCompactedEvaluatedGuidanceRoundtripsThroughOutcomeLogger(): void
    {
        $learningRoot = $this->root . '/learning';
        mkdir($learningRoot . '/proposals/applied', 0777, true);
        mkdir($learningRoot . '/findings/validated', 0777, true);
        mkdir($learningRoot . '/history', 0777, true);

        file_put_contents($learningRoot . '/findings/validated/finding.2026-06-01.001.json', json_encode([
            'id' => 'finding.2026-06-01.001',
            'task_id' => 'TASK-1',
            'session' => 's1',
            'created_at' => '2026-06-01T08:00:00+00:00',
            'created_by' => 'test',
            'scope' => ['src/Auth/'],
            'observation' => 'Auth test',
            'evidence' => [['type' => 'test_result', 'command' => 'test', 'summary' => 'pass']],
            'hypothesis' => 'Auth rule',
            'validated_conclusion' => 'Strict auth',
            'confidence' => 'high',
            'validation_status' => 'validated',
            'status' => 'validated',
            'sensitivity' => 'public',
        ], JSON_THROW_ON_ERROR));

        file_put_contents($learningRoot . '/proposals/applied/proposal.2026-06-01.001.json', json_encode([
            'schema_version' => '1.0',
            'id' => 'proposal.2026-06-01.001',
            'created_at' => '2026-06-01T09:00:00+00:00',
            'action' => 'ADD',
            'target_type' => 'skill',
            'target' => 'skill.auth-rule',
            'scope' => ['src/Auth/'],
            'source_findings' => ['finding.2026-06-01.001'],
            'tags' => ['auth'],
            'new' => 'Auth invariant.',
            'reason' => 'Prevent auth regression.',
            'validation' => ['make test'],
            'status' => 'applied',
            'proposed_by' => 'agent-loop',
            'approved_by' => 'reviewer',
            'approved_at' => '2026-06-01T10:00:00+00:00',
        ], JSON_THROW_ON_ERROR));

        // Compact item with no task_files and no selection_reason
        $unselectedGuidance = new EvaluatedGuidance(
            'proposal.2026-06-01.001',
            GuidanceType::SKILL,
            false,
            false,
            null,
            ExclusionReason::NO_SCOPE_OVERLAP,
            [],
        );

        $compactArray = $unselectedGuidance->toArray();
        self::assertArrayNotHasKey('selection_reason', $compactArray);
        self::assertArrayNotHasKey('task_files', $compactArray);

        // Draft containing the compacted evaluated guidance
        $draftPath = $this->root . '/recall-log.draft.json';
        file_put_contents($draftPath, json_encode([
            'schema_version' => '1.0',
            'id' => 'outcome.2026-06-01.001',
            'compilation_id' => 'compilation.TEST.001',
            'task_id' => 'TASK-LOG-1',
            'session' => 'sess-1',
            'created_at' => '2026-06-01T12:00:00+00:00',
            'task_files' => ['src/Billing/Invoice.php'],
            'evaluated_guidance' => [$compactArray],
            'guidance_outcomes' => [],
            'result' => 'successful',
        ], JSON_THROW_ON_ERROR));

        $logger = new OutcomeLogger();
        $result = $logger->log($learningRoot, $draftPath, 'actor', 'commit_hash');

        self::assertSame('compilation.TEST.001', $result);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $p = $dir . '/' . $file;
            is_dir($p) ? $this->removeDirectory($p) : unlink($p);
        }
        rmdir($dir);
    }
}
