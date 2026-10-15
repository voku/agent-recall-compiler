<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentRecallCompiler\Cli;

final class MapEvidenceAbsenceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-recall-map-absence-' . bin2hex(random_bytes(6));
        foreach ([
            '/proposals/approved',
            '/proposals/applied',
            '/proposals/rejected',
            '/constraints/active',
            '/history',
        ] as $path) {
            self::assertTrue(mkdir($this->root . $path, 0777, true));
        }
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

    public function testKnownPhpFilesWithoutMapExposeUnavailableSymbolEvidence(): void
    {
        $output = $this->compile(['src/Service/Example.php', 'tests/ExampleTest.php']);
        $facts = $this->read($output . '/facts.json');
        $bundle = $this->read($output . '/recall.bundle.json');
        $explain = $this->read($output . '/selection-report.json');
        $system = $this->read($output . '/system.md');

        self::assertSame(1, substr_count($facts, '"map.symbol-context.unavailable"'));
        self::assertStringContainsString('"reason_code": "map_index_not_configured"', $facts);
        self::assertStringContainsString('"status": "unavailable"', $facts);
        self::assertStringContainsString('"src/Service/Example.php"', $facts);
        self::assertStringContainsString('"tests/ExampleTest.php"', $facts);
        self::assertStringContainsString('"map.symbol-context.unavailable"', $bundle);
        self::assertStringContainsString('"kind": "navigation_status"', $explain);
        self::assertStringContainsString('"state": "unknown"', $explain);
        self::assertStringContainsString('## Indexed Navigation Availability', $system);
        self::assertStringContainsString('**UNAVAILABLE**', $system);
        self::assertStringContainsString('no indexed symbol evidence was compiled', $system);
        self::assertStringContainsString('does not prove that the declared source or its symbols are absent', $system);
    }

    public function testNonPhpTaskDoesNotInventMissingMapRequirements(): void
    {
        $output = $this->compile(['composer.json']);
        $facts = $this->read($output . '/facts.json');
        $system = $this->read($output . '/system.md');

        self::assertStringNotContainsString('map.symbol-context.unavailable', $facts);
        self::assertStringNotContainsString('## Indexed Navigation Availability', $system);
    }

    /** @param list<string> $files */
    private function compile(array $files): string
    {
        $output = $this->root . '/output';
        $args = [
            'agent-recall-compiler',
            'compile',
            '--root',
            $this->root,
            '--task',
            'MAP-ABSENCE-1',
            '--description',
            'Inspect the declared files before claiming a symbol-level result.',
            '--output-dir',
            $output,
            '--compilation-id',
            'compilation.MAP-ABSENCE-1.fixed',
        ];
        foreach ($files as $file) {
            array_push($args, '--file', $file);
        }

        self::assertSame(0, (new Cli())->run($args));

        return $output;
    }

    private function read(string $path): string
    {
        $content = file_get_contents($path);
        self::assertIsString($content);

        return $content;
    }
}
