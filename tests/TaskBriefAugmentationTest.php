<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\AnalysisFingerprint;
use voku\AgentMap\Index\FileEntry;
use voku\AgentMap\Index\IndexWriter;
use voku\AgentMap\Index\MethodEntry;
use voku\AgentMap\Index\SymbolEntry;
use voku\AgentRecallCompiler\Cli;

final class TaskBriefAugmentationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-recall-task-augmentation-' . bin2hex(random_bytes(6));
        foreach (['constraints/active', 'proposals/approved', 'proposals/applied', 'rejections', 'retired', 'history', 'src'] as $directory) {
            self::assertTrue(mkdir($this->root . '/' . $directory, 0o775, true));
        }
        file_put_contents(
            $this->root . '/src/Foo.php',
            "<?php\nnamespace Demo;\nfinal class Foo\n{\n    public function bar(): string { return 'ok'; }\n}\n",
        );
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

    public function testAdditionalTargetPreservesAcceptanceCriteriaAndGovernedRunBinding(): void
    {
        $input = $this->writeGovernedInput();
        $mapPath = $this->writeMap();
        $output = $this->root . '/out-target';

        self::assertSame(0, (new Cli())->run([
            'agent-recall-compiler',
            'compile',
            '--root', $this->root,
            '--task-brief', $input,
            '--map-index', $mapPath,
            '--map-root', $this->root,
            '--target', 'Demo\\Foo::bar',
            '--output-dir', $output,
        ]));

        $this->assertTaskContext($output . '/facts.json');
        self::assertStringContainsString('## Acceptance Criteria', (string) file_get_contents($output . '/system.md'));
    }

    public function testAdditionalOperatingPromptPreservesAcceptanceCriteriaAndGovernedRunBinding(): void
    {
        $input = $this->writeGovernedInput();
        $manifest = $this->root . '/operating-prompts.json';
        file_put_contents($manifest, json_encode([
            'schema_version' => '1.0',
            'prompts' => [[
                'id' => 'evidence-report',
                'level' => 1,
                'template' => 'Report exact observed evidence only.',
            ]],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $output = $this->root . '/out-prompt';

        self::assertSame(0, (new Cli())->run([
            'agent-recall-compiler',
            'compile',
            '--root', $this->root,
            '--task-brief', $input,
            '--operating-prompt-manifest', $manifest,
            '--operating-prompt', '{"id":"evidence-report","arguments":{}}',
            '--output-dir', $output,
        ]));

        $this->assertTaskContext($output . '/facts.json');
        self::assertStringContainsString('## Acceptance Criteria', (string) file_get_contents($output . '/system.md'));
    }

    private function writeGovernedInput(): string
    {
        $contract = $this->root . '/contract.json';
        file_put_contents($contract, json_encode([
            'schema_version' => '1.0',
            'kind' => 'task_contract',
            'task_id' => 'TASK-AUGMENT',
            'goal' => 'Preserve task semantics while adding compile-time context.',
            'scope' => ['src/Foo.php'],
            'non_goals' => [],
            'validation' => ['composer test'],
            'acceptance_criteria' => [
                'Acceptance intent survives task augmentation.',
                'Governed Run lineage survives task augmentation.',
            ],
            'tags' => [],
            'behavior_anchors' => [],
            'operating_prompt_manifest' => null,
            'operating_prompts' => [],
            'status' => 'approved',
            'revision' => 1,
            'planned_by' => 'agent',
            'base_commit' => null,
            'approved_by' => 'human',
            'approved_at' => '2026-08-13T08:00:00+00:00',
            'created_at' => '2026-08-13T07:59:00+00:00',
            'updated_at' => '2026-08-13T08:00:00+00:00',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $digest = hash_file('sha256', $contract);
        self::assertIsString($digest);

        $input = $this->root . '/recall-input.json';
        file_put_contents($input, json_encode([
            'schema_version' => '1.0',
            'kind' => 'governed_recall_input',
            'run_id' => 'run:TASK-AUGMENT:0123456789abcdef',
            'contract' => [
                'path' => 'contract.json',
                'sha256' => 'sha256:' . $digest,
                'revision' => 1,
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $input;
    }

    private function writeMap(): string
    {
        $source = $this->root . '/src/Foo.php';
        $hash = hash_file('sha256', $source);
        self::assertIsString($hash);

        $symbol = new SymbolEntry(
            kind: 'class',
            name: 'Foo',
            fqn: 'Demo\\Foo',
            lineStart: 3,
            lineEnd: 6,
            methods: [new MethodEntry(
                'bar',
                'public',
                5,
                5,
                nativeReturnType: 'string',
                resolvedReturnType: 'string',
                reconciliationStatus: 'confirmed',
            )],
            reconciliationStatus: 'confirmed',
        );
        $index = new AgentMapIndex(
            schemaVersion: '2.0',
            root: $this->root,
            backend: 'phpstan+simple-parser',
            files: [new FileEntry('src/Foo.php', 'sha256:' . $hash, 'Demo', [$symbol], 'analysed')],
            relations: [],
            fingerprint: new AnalysisFingerprint(
                phpStanVersion: '2.1.0',
                phpStanConfigSha256: 'sha256:config',
                composerLockSha256: 'sha256:lock',
                sourceDigest: 'sha256:source',
            ),
        );
        $path = $this->root . '/map.json';
        (new IndexWriter())->write($index, $path);

        return $path;
    }

    private function assertTaskContext(string $factsPath): void
    {
        $document = json_decode((string) file_get_contents($factsPath), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        $facts = $document['facts'] ?? null;
        self::assertIsArray($facts);

        $matches = array_values(array_filter(
            $facts,
            static fn (mixed $fact): bool => is_array($fact)
                && ($fact['id'] ?? null) === 'task.TASK-AUGMENT'
                && ($fact['type'] ?? null) === 'task_context',
        ));
        self::assertCount(1, $matches);
        $payload = $matches[0]['payload'] ?? null;
        self::assertIsArray($payload);
        self::assertSame([
            'Acceptance intent survives task augmentation.',
            'Governed Run lineage survives task augmentation.',
        ], $payload['acceptance_criteria'] ?? null);
        self::assertSame('run:TASK-AUGMENT:0123456789abcdef', $payload['governed_run']['run_id'] ?? null);
    }
}
