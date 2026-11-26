<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentRecallCompiler\Cli;
use voku\AgentRecallCompiler\CompileRequest;
use voku\AgentRecallCompiler\RecallCompiler;

final class ItpSourceHintsCompilationTest extends TestCase
{
    private string $root;
    private string $learning;
    private string $sourcePath;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/recall-itp-hints-' . bin2hex(random_bytes(8));
        $this->learning = $this->root . '/.agent-loop/learning';
        $this->sourcePath = $this->root . '/src/Example.php';
        foreach (['src', '.agent-loop/learning/proposals/approved', '.agent-loop/learning/proposals/applied',
            '.agent-loop/learning/proposals/rejected', '.agent-loop/learning/constraints/active',
            '.agent-loop/learning/history'] as $dir) {
            if (!mkdir($this->root . '/' . $dir, 0o775, true) && !is_dir($this->root . '/' . $dir)) {
                throw new RuntimeException('Could not create fixture directory: ' . $dir);
            }
        }
        file_put_contents($this->sourcePath, <<<'PHP'
<?php
namespace Example;
#[\ItpContext\Attribute\Rule(\Demo\Rules::ReadTests)]
final class Example {}
PHP);
        $sha = hash_file('sha256', $this->sourcePath);
        self::assertIsString($sha);
        file_put_contents($this->root . '/hints.json', json_encode([
            'owner' => 'itp-context',
            'status' => 'ready',
            'authority' => 'none',
            'resolution' => 'syntax_only',
            'rules' => [[
                'rule_id' => 'Demo\Rules::ReadTests',
                'subjects' => ['Example\Example'],
                'files' => ['src/Example.php'],
                'evidence' => 'source_attribute_only',
            ]],
            'inspected_files' => [[
                'path' => 'src/Example.php',
                'sha256' => 'sha256:' . $sha,
            ]],
            'ignored_paths' => 0,
            'omitted_rules' => 0,
        ], JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    public function testOwnerSourceHintsReachL2PromptWithoutExpandingContractScope(): void
    {
        $output = $this->compile('with-hints', true);
        $facts = json_decode((string) file_get_contents($output . '/facts.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($facts);
        $hints = array_values(array_filter(
            $facts['facts'],
            static fn (array $fact): bool => $fact['type'] === 'architecture_hint',
        ));
        self::assertCount(1, $hints);
        self::assertSame('derived_navigation', $hints[0]['authority']);
        self::assertSame('Demo\Rules::ReadTests', $hints[0]['payload']['rule_id']);

        $system = (string) file_get_contents($output . '/system.md');
        self::assertStringContainsString('PHP Architecture Rule Leads (Unverified)', $system);
        self::assertStringContainsString('Demo\Rules::ReadTests', $system);
        self::assertStringContainsString('verifiedBy tests, ADR refs and changeGuidance', $system);
        self::assertStringContainsString('not approved edit scope', $system);

        $bundle = json_decode((string) file_get_contents($output . '/recall.bundle.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['src/Example.php'], $bundle['task']['files']);
        self::assertSame(['src/Example.php'], $bundle['effective_scope']['files']);
        $providers = array_values(array_filter(
            $bundle['snapshot']['providers'],
            static fn (array $provider): bool => $provider['manifest']['id'] === 'itp-source-hints',
        ));
        self::assertCount(1, $providers);
    }

    public function testStaleOwnerSourceHashDoesNotLeakObsoleteArchitectureRule(): void
    {
        file_put_contents($this->sourcePath, "\n// changed after owner's scan", FILE_APPEND);
        $output = $this->compile('stale-hints', true);
        $facts = json_decode((string) file_get_contents($output . '/facts.json'), true, 512, JSON_THROW_ON_ERROR);
        $statuses = array_values(array_filter(
            $facts['facts'],
            static fn (array $fact): bool => $fact['type'] === 'architecture_status',
        ));
        self::assertCount(1, $statuses);
        self::assertSame('stale', $statuses[0]['payload']['status']);
        $system = (string) file_get_contents($output . '/system.md');
        self::assertStringContainsString('Incomplete, unavailable or stale', $system);
        self::assertStringNotContainsString('Demo\Rules::ReadTests', $system);
    }

    public function testOwnerOmissionsAreExplicitlyVisibleToTheHost(): void
    {
        $path = $this->root . '/hints.json';
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $data['ignored_paths'] = 2;
        file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR));

        $out = $this->compile('partial-hints', true);
        $facts = json_decode((string) file_get_contents($out . '/facts.json'), true, 512, JSON_THROW_ON_ERROR);
        $partial = array_values(array_filter(
            $facts['facts'],
            static fn (array $fact): bool => ($fact['type'] ?? null) === 'architecture_status',
        ));
        self::assertCount(1, $partial);
        self::assertSame('partial', $partial[0]['payload']['status']);
        self::assertStringContainsString(
            'Incomplete, unavailable or stale',
            (string) file_get_contents($out . '/system.md'),
        );
    }

    public function testCompilationWithoutOptionalOwnerHintsKeepsExistingBehavior(): void
    {
        $output = $this->compile('without-hints', false);
        $system = (string) file_get_contents($output . '/system.md');
        self::assertStringNotContainsString('PHP Architecture Rule Leads', $system);
    }

    public function testRejectsRulesReferencingSourcesOutsideOwnerSnapshot(): void
    {
        $path = $this->root . '/hints.json';
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $data['rules'][0]['files'] = ['tests/NotInspectedTest.php'];
        file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR));

        self::assertSame(1, (new Cli())->run([
            'agent-recall-compiler', 'compile',
            '--root', $this->learning,
            '--task', 'TEST-ITP',
            '--file', 'src/Example.php',
            '--itp-source-hints', $path,
            '--output-dir', $this->root . '/invalid-out',
            '--compilation-id', 'compilation.TEST-ITP.invalid',
        ]));
        self::assertFileDoesNotExist($this->root . '/invalid-out/system.md');
    }

    public function testTypedHostApiCanSupplyTheSameOwnerProjection(): void
    {
        file_put_contents($this->root . '/task.json', json_encode([
            'schema_version' => '1.0',
            'id' => 'TEST-ITP-TYPED',
            'description' => 'Use owner hints in a host-provided typed compilation.',
            'files' => ['src/Example.php'],
        ], JSON_THROW_ON_ERROR));

        $out = $this->root . '/typed-out';
        (new RecallCompiler())->compile(new CompileRequest(
            learningRoot: $this->learning,
            taskBrief: $this->root . '/task.json',
            outputDirectory: $out,
            itpSourceHints: $this->root . '/hints.json',
            compilationId: 'compilation.TEST-ITP-TYPED.hints',
        ));

        self::assertStringContainsString(
            'Demo\Rules::ReadTests',
            (string) file_get_contents($out . '/system.md'),
        );
    }

    private function compile(string $suffix, bool $withHints): string
    {
        $output = $this->root . '/out-' . $suffix;
        $options = [
            'agent-recall-compiler', 'compile',
            '--root', $this->learning,
            '--task', 'TEST-ITP',
            '--description', 'Inspect the source architecture before changing the PHP class.',
            '--file', 'src/Example.php',
            '--output-dir', $output,
            '--compilation-id', 'compilation.TEST-ITP.' . $suffix,
        ];
        if ($withHints) {
            array_push($options, '--itp-source-hints', $this->root . '/hints.json');
        }
        self::assertSame(0, (new Cli())->run($options));

        return $output;
    }
}
