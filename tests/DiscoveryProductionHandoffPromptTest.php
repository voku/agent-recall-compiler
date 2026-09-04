<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentRecallCompiler\Cli;

final class DiscoveryProductionHandoffPromptTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-recall-discovery-handoff-' . bin2hex(random_bytes(6));
        foreach ([
            '/proposals/approved',
            '/proposals/applied',
            '/proposals/rejected',
            '/constraints/active',
            '/history',
        ] as $directory) {
            self::assertTrue(mkdir($this->root . $directory, 0777, true));
        }
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
            $path = $item->getPathname();
            $item->isDir() ? rmdir($path) : unlink($path);
        }
        rmdir($this->root);
    }

    public function testDiscoveryFirstCompilesRepositoryGroundedDiscoveryContract(): void
    {
        $system = $this->compileRecipe(
            'discovery-first',
            'DISCOVERY-FIRST-1',
            'Inspect the current implementation before deciding the smallest safe follow-up.',
        );

        self::assertStringContainsString('### discovery-first (L2)', $system);
        self::assertStringContainsString('before proposing or implementing a change', $system);
        self::assertStringContainsString('already-completed or disproved work', $system);
        self::assertStringContainsString('VERIFIED, INFERRED, UNKNOWN, BLOCKED, or CONTRADICTED', $system);
        self::assertStringContainsString('bounded falsification of plausible stale-assumption, regression, ownership, dependency/toolchain, and measurement-definition risks', $system);
        self::assertStringContainsString('NO_CHANGE is valid', $system);
        self::assertStringContainsString('smallest evidence-backed next slice', $system);
        self::assertStringContainsString('semantic owner boundary', $system);
        self::assertStringContainsString('exact verification or discriminating probe needed before editing', $system);
        self::assertStringContainsString('If authoritative context is missing or conflicting, return BLOCKED', $system);
        self::assertStringContainsString('Do not implement during the discovery pass', $system);
    }

    public function testProductionReadyHandoffCompilesFreshAgentExecutionContract(): void
    {
        $system = $this->compileRecipe(
            'production-ready-handoff',
            'PRODUCTION-HANDOFF-1',
            'Turn the verified discovery into a production-ready prompt for a fresh coding agent.',
        );

        self::assertStringContainsString('### production-ready-handoff (L2)', $system);
        self::assertStringContainsString('has no access to the current chat, Session-private context, hidden reasoning, or prior-agent memory', $system);
        self::assertStringContainsString('re-ground all supplied anchors against current repository state before acting', $system);
        self::assertStringContainsString('preserve VERIFIED, INFERRED, UNKNOWN, BLOCKED, and CONTRADICTED distinctions', $system);
        self::assertStringContainsString('already-completed work and disproved hypotheses', $system);
        self::assertStringContainsString('task authority, semantic owner boundaries', $system);
        self::assertStringContainsString('repository-supported positive and negative-path tests', $system);
        self::assertStringContainsString('stale-artifact/retry/reproducibility checks when relevant', $system);
        self::assertStringContainsString('exact validation commands or explicit UNKNOWN discovery obligations', $system);
        self::assertStringContainsString('falsification questions that try to disprove the proposed fix', $system);
        self::assertStringContainsString('observable Done When criteria', $system);
        self::assertStringContainsString('Production-ready must not mean broader scope', $system);
        self::assertStringContainsString('current authority wins', $system);
        self::assertStringContainsString('re-planning or BLOCKED', $system);
        self::assertStringContainsString('copy-paste-ready L1 execution prompt', $system);
        self::assertStringContainsString('not a summary, TODO card, implementation, or claim of verification', $system);
    }

    public function testTodoCardHandoffCompilesDurableWorkPackagePlanningContract(): void
    {
        $system = $this->compileRecipe(
            'todo-card-handoff',
            'TODO-HANDOFF-1',
            'Create durable TODO card guidance from current discovery evidence.',
        );

        self::assertStringContainsString('### todo-card-handoff (L2)', $system);
        self::assertStringContainsString('self-contained TODO or work cards for a coding agent', $system);
        self::assertStringContainsString('no access to the current chat, Session-private context', $system);
        self::assertStringContainsString('Treat the generated cards as work-package candidates, not approved Contract/Run authority', $system);
        self::assertStringContainsString('VERIFIED current state and already-completed work', $system);
        self::assertStringContainsString('exact repository anchors needed to resume', $system);
        self::assertStringContainsString('smallest concrete next implementation steps', $system);
        self::assertStringContainsString('observable acceptance criteria and Done When', $system);
        self::assertStringContainsString('final host-specific execution belongs in a later explicit dispatch step', $system);
    }

    public function testExecutionDispatchCompilesBoundedSliceContract(): void
    {
        $system = $this->compileRecipe(
            'execution-dispatch',
            'DISPATCH-1',
            'Create a short execution prompt for the next bounded slice.',
        );

        self::assertStringContainsString('### execution-dispatch (L2)', $system);
        self::assertStringContainsString('exactly one current bounded execution slice that already has a durable task or work-package owner', $system);
        self::assertStringContainsString('Require exact lineage to the selected durable task/work-package revision and the current approved Contract/Run/stage authority', $system);
        self::assertStringContainsString('a durable card is not approval, and a generated dispatch prompt is not workflow authority', $system);
        self::assertStringContainsString('Re-ground current repository anchors at dispatch time and include only the current slice', $system);
        self::assertStringContainsString('Do not copy the entire durable backlog, issue history, prior chat, Session-private context', $system);
        self::assertStringContainsString('Current repository, Contract/Run/stage, and bounded environment evidence win over stale dispatch text', $system);
        self::assertStringContainsString('copy-paste-ready L1 prompt for the current slice only', $system);
    }

    private function compileRecipe(string $id, string $task, string $description): string
    {
        $manifest = dirname(__DIR__) . '/resources/skills/agent-recall-consumer/operating-prompts.json';
        self::assertFileExists($manifest);

        $output = $this->root . '/output-' . strtolower($task);
        $request = json_encode([
            'id' => $id,
            'arguments' => [],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        self::assertSame(0, (new Cli())->run([
            'agent-recall-compiler',
            'compile',
            '--root',
            $this->root,
            '--task',
            $task,
            '--description',
            $description,
            '--file',
            'src/Example.php',
            '--operating-prompt-manifest',
            $manifest,
            '--operating-prompt',
            $request,
            '--output-dir',
            $output,
            '--compilation-id',
            'compilation.' . $task . '.fixed',
        ]));

        return (string) file_get_contents($output . '/system.md');
    }
}
