<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use voku\AgentRecallCompiler\Cli;
use voku\AgentRecallCompiler\OutcomeLogger;
use voku\AgentRecallCompiler\RecallSelectionEvent;
use voku\AgentRecallCompiler\Reflection\GuidanceGapPromptBuilder;

final class BundledOperatingPromptCatalogTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-recall-bundled-prompts-' . bin2hex(random_bytes(6));
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

    public function testBundledAdversarialReviewCompilesThroughTheRealCli(): void
    {
        $manifest = dirname(__DIR__) . '/skills/agent-recall-consumer/operating-prompts.json';
        self::assertFileExists($manifest);

        $output = $this->root . '/output';
        $request = json_encode([
            'id' => 'adversarial-review',
            'arguments' => ['minimum_failure_modes' => 3],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        self::assertSame(0, (new Cli())->run([
            'agent-recall-compiler',
            'compile',
            '--root',
            $this->root,
            '--task',
            'BUNDLED-PROMPT-1',
            '--description',
            'Review the current implementation as a first draft.',
            '--file',
            'src/Example.php',
            '--operating-prompt-manifest',
            $manifest,
            '--operating-prompt',
            $request,
            '--output-dir',
            $output,
            '--compilation-id',
            'compilation.BUNDLED-PROMPT-1.fixed',
        ]));

        $system = (string) file_get_contents($output . '/system.md');
        self::assertStringContainsString('### adversarial-review (L2)', $system);
        self::assertStringContainsString('distinct plausible failure-mode hypotheses or attack scenarios', $system);
        self::assertStringContainsString('Do not manufacture defects merely to satisfy the numeric floor', $system);
        self::assertStringContainsString('CLEAN remains valid', $system);
    }

    public function testConsumerSkillMatchesCurrentCliDefaultsAndCommands(): void
    {
        $skill = (string) file_get_contents(dirname(__DIR__) . '/skills/agent-recall-consumer/SKILL.md');

        self::assertStringNotContainsString('infra/doc/agent-learning', $skill);
        self::assertStringNotContainsString('.agent-recall-output', $skill);
        self::assertStringContainsString('<cwd>/.agent-loop/learning', $skill);
        self::assertStringContainsString('<cwd>/.agent-loop/recall/<task-id>', $skill);

        $cliFile = (new ReflectionClass(Cli::class))->getFileName();
        self::assertIsString($cliFile);
        $cliSource = (string) file_get_contents($cliFile);

        foreach (['compile', 'log-outcome', 'prompt', 'review'] as $command) {
            self::assertStringContainsString("'{$command}' =>", $cliSource);
            self::assertStringContainsString('agent-recall-compiler ' . $command, $skill);
        }
        self::assertStringContainsString('prompt future-work --scope project', $skill);
        self::assertStringContainsString('prompt guidance-gaps', $skill);
        self::assertStringContainsString('review first-draft', $skill);
    }

    public function testConsumerSkillMatchesGuidanceGapPromptContract(): void
    {
        $skill = (string) file_get_contents(dirname(__DIR__) . '/skills/agent-recall-consumer/SKILL.md');
        $prompt = (new GuidanceGapPromptBuilder())->build();

        foreach (['implementation-notes.html', 'HUMAN_DECISION_REQUIRED'] as $needle) {
            self::assertStringContainsString($needle, $prompt);
            self::assertStringContainsString($needle, $skill);
        }

        self::assertStringContainsString('not a default workflow stage', $prompt);
        self::assertStringContainsString('not a default workflow stage', $skill);
        self::assertStringContainsString('do not commit it unless', $prompt);
        self::assertStringContainsString('do not commit it unless', $skill);
        self::assertStringContainsString('Do not automatically edit documentation or skills', $prompt);
        self::assertStringContainsString('Do not automatically edit docs or skills', $skill);
    }

    public function testConsumerSkillMatchesOutcomeHonestyContract(): void
    {
        $skill = (string) file_get_contents(dirname(__DIR__) . '/skills/agent-recall-consumer/SKILL.md');

        $loggerFile = (new ReflectionClass(OutcomeLogger::class))->getFileName();
        self::assertIsString($loggerFile);
        self::assertStringContainsString(
            'guidance_outcomes_withheld_reason',
            (string) file_get_contents($loggerFile),
        );

        $selectionFile = (new ReflectionClass(RecallSelectionEvent::class))->getFileName();
        self::assertIsString($selectionFile);
        self::assertStringContainsString(
            'outcome_withheld_reason',
            (string) file_get_contents($selectionFile),
        );

        foreach (['guidance_outcomes_withheld_reason', 'outcome_withheld_reason'] as $field) {
            self::assertStringContainsString($field, $skill);
        }

        self::assertStringContainsString('An untouched compiler draft is not feedback', $skill);
        self::assertStringContainsString('An explicit `unknown` outcome requires a non-empty comment', $skill);
        self::assertStringContainsString('do not manufacture `not_used` or `irrelevant`', $skill);
        self::assertStringContainsString('Silent omission without that declared withholding fails', $skill);
    }
}
