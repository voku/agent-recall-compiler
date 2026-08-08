<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
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
        mkdir($this->root, 0777, true);
        $this->manifest = $this->root . '/operating-prompts.json';
        $this->writeManifest([
            [
                'id' => 'plan-horizon',
                'template' => "Plan the next {{horizon}}, not merely the next step.\nDone when the full horizon has measurable milestones.",
            ],
            [
                'id' => 'coverage-mutation',
                'template' => "Increase coverage by at least {{minimum_percentage_points}} percentage points.\nRun {{mutation_command}} and use surviving meaningful mutants as evidence that tests are still weak.",
            ],
        ]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/*') ?: [] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        rmdir($this->root);
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

    public function testSelectedPromptIsRenderedAsAnOperatingContract(): void
    {
        $task = new TaskBrief(
            id: 'OPS-2',
            description: 'Make planning measurable.',
            files: [],
            operatingPrompts: [new OperatingPromptRequest('plan-horizon', ['horizon' => '3 months'])],
        );
        $rootConfig = new RecallRootConfig($this->root, $this->root . '/constraints');
        $compilation = (new RecallCompilationService([
            new TaskContextRecallProvider(),
            new OperatingPromptRecallProvider([$this->manifest]),
        ]))->compile($task, $rootConfig);

        $markdown = (new OperatingPromptRenderer())->render($compilation->facts);

        self::assertStringContainsString('## Operating Contract', $markdown);
        self::assertStringContainsString('### plan-horizon', $markdown);
        self::assertStringContainsString('Plan the next 3 months, not merely the next step.', $markdown);
        self::assertSame('plan-horizon', $compilation->bundle['task']['operating_prompts'][0]['id']);
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
            'template' => 'Plan the entire {{horizon}} and prove every milestone.',
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

    public function testDuplicateManifestDefinitionsAreRejected(): void
    {
        $this->writeManifest([
            ['id' => 'plan-horizon', 'template' => 'Plan {{horizon}}.'],
            ['id' => 'plan-horizon', 'template' => 'Still plan {{horizon}}.'],
        ]);
        $task = new TaskBrief(
            id: 'OPS-7',
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

    /** @param list<array{id: string, template: string}> $prompts */
    private function writeManifest(array $prompts): void
    {
        file_put_contents($this->manifest, json_encode([
            'schema_version' => '1.0',
            'prompts' => $prompts,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
