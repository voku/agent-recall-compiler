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
        self::assertStringContainsString('NO_CHANGE is valid', $system);
        self::assertStringContainsString('smallest evidence-backed next slice', $system);
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
        self::assertStringContainsString('already-completed work and disproved hypotheses', $system);
        self::assertStringContainsString('Production-ready must not mean broader scope', $system);
        self::assertStringContainsString('falsification questions that try to disprove the proposed fix', $system);
        self::assertStringContainsString('copy-paste-ready L1 execution prompt', $system);
        self::assertStringContainsString('not a summary, TODO card, implementation, or claim of verification', $system);
    }

    private function compileRecipe(string $id, string $task, string $description): string
    {
        $manifest = dirname(__DIR__) . '/skills/agent-recall-consumer/operating-prompts.json';
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
