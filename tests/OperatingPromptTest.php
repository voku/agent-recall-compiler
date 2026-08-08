<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentRecallCompiler\Cli;
use voku\AgentRecallCompiler\Compilation\RecallCompilationService;
use voku\AgentRecallCompiler\OperatingPromptRequest;
use voku\AgentRecallCompiler\Provider\OperatingPromptRecallProvider;
use voku\AgentRecallCompiler\Provider\TaskContextRecallProvider;
use voku\AgentRecallCompiler\RecallRootConfig;
use voku\AgentRecallCompiler\Rendering\OperatingPromptRenderer;
use voku\AgentRecallCompiler\TaskBrief;
use voku\AgentRecallCompiler\TaskBriefParser;

final class OperatingPromptTest extends TestCase
{
    private string $root;
    private string $manifest;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-recall-operating-prompt-' . bin2hex(random_bytes(6));
        foreach ([
            '/proposals/approved',
            '/proposals/applied',
            '/proposals/rejected',
            '/constraints/active',
            '/history',
        ] as $directory) {
            mkdir($this->root . $directory, 0777, true);
        }
        $this->manifest = $this->root . '/operating-prompts.json';
        $this->writeManifest([
            [
                'id' => 'plan-horizon',
                'level' => 2,
                'template' => "Create a project-specific planning prompt for the next {{horizon}}.\nUse concrete repository context.",
            ],
            [
                'id' => 'coverage-mutation',
                'level' => 2,
                'template' => "Create a project-specific test prompt that increases coverage by at least {{minimum_percentage_points}} percentage points.\nRequire {{mutation_command}}.",
            ],
            [
                'id' => 'evidence-report',
                'level' => 1,
                'template' => 'Report only success that is backed by observable evidence.',
            ],
        ]);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testTaskBriefParserKeepsTypedOperatingPromptRequests(): void
    {
        $briefPath = $this->root . '/work-brief.json';
        file_put_contents($briefPath, json_encode([
            'schema_version' => '1.0',
            'task_id' => 'OPS-1',
            'goal' => 'Plan beyond the immediate next action.',
            'operating_prompts' => [[
                'id' => 'plan-horizon',
                'arguments' => ['horizon' => '3 months'],
            ]],
        ], JSON_THROW_ON_ERROR));

        $task = (new TaskBriefParser())->parseFile($briefPath);

        self::assertCount(1, $task->operatingPrompts);
        self::assertSame([
            'id' => 'plan-horizon',
            'arguments' => ['horizon' => '3 months'],
        ], $task->operatingPrompts[0]->toArray());
    }

    public function testCliCompilesL2PromptRecipeIntoSystemBriefing(): void
    {
        $output = $this->root . '/cli-output';
        $request = json_encode([
            'id' => 'coverage-mutation',
            'arguments' => [
                'minimum_percentage_points' => 10,
                'mutation_command' => 'vendor/bin/infection --threads=max',
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        self::assertSame(0, (new Cli())->run([
            'agent-recall-compiler',
            'compile',
            '--root',
            $this->root,
            '--task',
            'OPS-CLI',
            '--description',
            'Make coverage growth prove something.',
            '--file',
            'src/Parser.php',
            '--file',
            'tests/ParserTest.php',
            '--operating-prompt-manifest',
            $this->manifest,
            '--operating-prompt',
            $request,
            '--output-dir',
            $output,
            '--compilation-id',
            'compilation.OPS-CLI.fixed',
        ]));

        $system = (string) file_get_contents($output . '/system.md');
        self::assertStringContainsString('## L2 Operational Prompt Construction', $system);
        self::assertStringContainsString('Goal', $system);
        self::assertStringContainsString('Context', $system);
        self::assertStringContainsString('Constraints', $system);
        self::assertStringContainsString('Done When', $system);
        self::assertStringContainsString('Create a project-specific test prompt', $system);
        self::assertStringContainsString('at least 10 percentage points', $system);
        self::assertStringContainsString('vendor/bin/infection --threads=max', $system);
        self::assertStringContainsString('Do not implement the task during prompt construction.', $system);

        $bundle = json_decode((string) file_get_contents($output . '/recall.bundle.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('coverage-mutation', $bundle['task']['operating_prompts'][0]['id']);
        self::assertContains(
            'operating-prompts',
            array_map(static fn (array $provider): string => $provider['manifest']['id'], $bundle['snapshot']['providers']),
        );
    }

    public function testRendererSeparatesL2RecipesFromL1Contracts(): void
    {
        $task = new TaskBrief(
            id: 'OPS-2',
            description: 'Make planning measurable.',
            files: ['src/Planner.php'],
            operatingPrompts: [
                new OperatingPromptRequest('plan-horizon', ['horizon' => '3 months']),
                new OperatingPromptRequest('evidence-report'),
            ],
        );
        $rootConfig = new RecallRootConfig($this->root, $this->root . '/constraints');
        $compilation = (new RecallCompilationService([
            new TaskContextRecallProvider(),
            new OperatingPromptRecallProvider([$this->manifest]),
        ]))->compile($task, $rootConfig);

        $markdown = (new OperatingPromptRenderer())->render($compilation->facts);

        self::assertStringContainsString('## L2 Operational Prompt Construction', $markdown);
        self::assertStringContainsString('### plan-horizon (L2)', $markdown);
        self::assertStringContainsString('Create a project-specific planning prompt for the next 3 months.', $markdown);
        self::assertStringContainsString('## L1 Operating Contract', $markdown);
        self::assertStringContainsString('### evidence-report (L1)', $markdown);
    }

    public function testSelectedTemplateChangeChangesProviderDigest(): void
    {
        $task = new TaskBrief(
            id: 'OPS-3',
            description: '',
            files: [],
            operatingPrompts: [new OperatingPromptRequest('plan-horizon', ['horizon' => '3 months'])],
        );
        $rootConfig = new RecallRootConfig($this->root, $this->root . '/constraints');
        $provider = new OperatingPromptRecallProvider([$this->manifest]);
        $first = $provider->collect($task, $rootConfig);

        $this->writeManifest([[
            'id' => 'plan-horizon',
            'level' => 2,
            'template' => 'Create a different project-specific plan for {{horizon}}.',
        ]]);
        $second = $provider->collect($task, $rootConfig);

        self::assertNotSame($first->sourceDigest, $second->sourceDigest);
    }

    public function testMissingPromptArgumentsAreRejected(): void
    {
        $task = new TaskBrief(
            id: 'OPS-4',
            description: '',
            files: [],
            operatingPrompts: [new OperatingPromptRequest('coverage-mutation', [
                'minimum_percentage_points' => 10,
            ])],
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('missing arguments: mutation_command');

        (new OperatingPromptRecallProvider([$this->manifest]))->collect(
            $task,
            new RecallRootConfig($this->root, $this->root . '/constraints'),
        );
    }

    public function testExtraPromptArgumentsAreRejected(): void
    {
        $task = new TaskBrief(
            id: 'OPS-5',
            description: '',
            files: [],
            operatingPrompts: [new OperatingPromptRequest('plan-horizon', [
                'horizon' => '3 months',
                'wishful_thinking' => true,
            ])],
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unknown arguments: wishful_thinking');

        (new OperatingPromptRecallProvider([$this->manifest]))->collect(
            $task,
            new RecallRootConfig($this->root, $this->root . '/constraints'),
        );
    }

    public function testUnknownPromptIdIsRejected(): void
    {
        $task = new TaskBrief(
            id: 'OPS-6',
            description: '',
            files: [],
            operatingPrompts: [new OperatingPromptRequest('does-not-exist')],
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unknown operating prompt id: does-not-exist');

        (new OperatingPromptRecallProvider([$this->manifest]))->collect(
            $task,
            new RecallRootConfig($this->root, $this->root . '/constraints'),
        );
    }

    public function testMissingPromptLevelIsRejected(): void
    {
        $this->writeManifest([[
            'id' => 'plan-horizon',
            'template' => 'Plan {{horizon}}.',
        ]]);
        $task = new TaskBrief(
            id: 'OPS-7',
            description: '',
            files: [],
            operatingPrompts: [new OperatingPromptRequest('plan-horizon', ['horizon' => '3 months'])],
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('requires level 1 or 2');

        (new OperatingPromptRecallProvider([$this->manifest]))->collect(
            $task,
            new RecallRootConfig($this->root, $this->root . '/constraints'),
        );
    }

    public function testDuplicateManifestDefinitionsAreRejected(): void
    {
        $this->writeManifest([
            ['id' => 'plan-horizon', 'level' => 2, 'template' => 'Plan {{horizon}}.'],
            ['id' => 'plan-horizon', 'level' => 2, 'template' => 'Still plan {{horizon}}.'],
        ]);
        $task = new TaskBrief(
            id: 'OPS-8',
            description: '',
            files: [],
            operatingPrompts: [new OperatingPromptRequest('plan-horizon', ['horizon' => '3 months'])],
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('operating prompt id is defined more than once: plan-horizon');

        (new OperatingPromptRecallProvider([$this->manifest]))->collect(
            $task,
            new RecallRootConfig($this->root, $this->root . '/constraints'),
        );
    }

    /** @param list<array{id: string, level?: 1|2, template: string}> $prompts */
    private function writeManifest(array $prompts): void
    {
        file_put_contents($this->manifest, json_encode([
            'schema_version' => '1.0',
            'prompts' => $prompts,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }
}
