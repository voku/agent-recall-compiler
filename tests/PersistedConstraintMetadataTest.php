<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentRecallCompiler\Cli;
use voku\AgentRecallCompiler\Output\CompiledContextExplanationReader;

/**
 * A new compilation must persist the constraint metadata it actually selected.
 *
 * The typed reader has always been able to expose scope, validation commands,
 * status and tags; the writer dropped them, so every compilation looked like a
 * legacy one. The tempting repair - looking the values up in current Learning
 * state while reading an old compilation - would present today's answer as the
 * historical one, so the fix belongs here, at write time.
 */
final class PersistedConstraintMetadataTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/recall-persisted-constraint-' . bin2hex(random_bytes(6));
        foreach (['constraints/active', 'proposals/approved', 'proposals/applied'] as $directory) {
            if (!mkdir($this->root . '/' . $directory, 0o775, true) && !is_dir($this->root . '/' . $directory)) {
                throw new RuntimeException('Unable to create constraint fixture root.');
            }
        }
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testCompilationPersistsScopeValidationStatusAndTagsForSelectedConstraints(): void
    {
        $this->writeConstraint();
        $outputDir = $this->compile();

        $explanation = (new CompiledContextExplanationReader())->read($outputDir);
        self::assertNotNull($explanation);

        self::assertCount(1, $explanation->constraints);
        $constraint = $explanation->constraints[0];

        self::assertSame('constraint.project.auth.no-direct-session-access', $constraint->id);
        self::assertSame('phpstan', $constraint->engine);
        self::assertSame('project.auth.no-direct-session-access', $constraint->ruleIdentifier);
        self::assertSame('proposal.2026-06-13.001', $constraint->sourceProposal);
        self::assertSame(['src/Auth'], $constraint->scope);
        self::assertSame(['vendor/bin/phpstan analyse'], $constraint->validationCommands);
        self::assertSame('active', $constraint->status);
        self::assertSame(['security'], $constraint->tags);
        self::assertTrue($constraint->hasExtendedMetadata());
    }

    public function testPersistedMetadataComesFromTheCompilationNotFromLaterState(): void
    {
        $this->writeConstraint();
        $outputDir = $this->compile();

        // The constraint changes after the compilation was written. Reading the
        // old compilation must still describe what that compilation selected.
        $this->writeConstraint(scope: ['src/Everything'], status: 'retired', tags: ['unrelated']);

        $explanation = (new CompiledContextExplanationReader())->read($outputDir);
        self::assertNotNull($explanation);
        $constraint = $explanation->constraints[0];

        self::assertSame(['src/Auth'], $constraint->scope);
        self::assertSame('active', $constraint->status);
        self::assertSame(['security'], $constraint->tags);
    }

    public function testSelectionReportAndBundleAgreeOnTheSameConstraintFacts(): void
    {
        $this->writeConstraint();
        $outputDir = $this->compile();

        $report = $this->decode($outputDir . '/selection-report.json');
        $bundle = $this->decode($outputDir . '/recall.bundle.json');

        self::assertSame(
            $report['selected_constraints'][0]['scope'],
            $bundle['selected_constraints'][0]['scope'],
        );
        self::assertSame(
            $report['selected_constraints'][0]['validation_commands'],
            $bundle['selected_constraints'][0]['validation_commands'],
        );
        self::assertSame($report['bundle_sha256'], $bundle['snapshot']['task_sha256'] ?? $report['bundle_sha256']);
    }

    public function testReadingAnExplanationNeverRecompiles(): void
    {
        $this->writeConstraint();
        $outputDir = $this->compile();
        $before = $this->snapshot($outputDir);

        (new CompiledContextExplanationReader())->read($outputDir);

        self::assertSame($before, $this->snapshot($outputDir));
    }

    /**
     * @param list<string> $scope
     * @param list<string> $tags
     */
    private function writeConstraint(
        array $scope = ['src/Auth'],
        string $status = 'active',
        array $tags = ['security'],
    ): void {
        file_put_contents(
            $this->root . '/constraints/active/constraint.project.auth.no-direct-session-access.json',
            json_encode([
                'schema_version' => '1.0',
                'id' => 'constraint.project.auth.no-direct-session-access',
                'engine' => 'phpstan',
                'rule_identifier' => 'project.auth.no-direct-session-access',
                'scope' => $scope,
                'validation_commands' => ['vendor/bin/phpstan analyse'],
                'source_proposal' => 'proposal.2026-06-13.001',
                'status' => $status,
                'tags' => $tags,
            ], JSON_THROW_ON_ERROR),
        );
    }

    private function compile(): string
    {
        $briefPath = $this->root . '/work-brief.json';
        file_put_contents($briefPath, json_encode([
            'schema_version' => '1.0',
            'task_id' => 'AUTH-1',
            'goal' => 'Touch the auth boundary.',
            'scope' => ['src/Auth/Login.php'],
            'validation' => ['vendor/bin/phpstan analyse'],
            'status' => 'approved',
            'revision' => 1,
        ], JSON_THROW_ON_ERROR));

        $outputDir = $this->root . '/output';
        $exit = (new Cli())->run([
            'agent-recall-compiler',
            'compile',
            '--root', $this->root,
            '--task-brief', $briefPath,
            '--compilation-id', 'compilation.AUTH-1.fixed',
            '--output-dir', $outputDir,
        ]);
        self::assertSame(0, $exit, 'compilation must succeed');

        return $outputDir;
    }

    /** @return array<string, mixed> */
    private function decode(string $path): array
    {
        $raw = file_get_contents($path);
        self::assertIsString($raw);
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /** @return list<string> */
    private function snapshot(string $path): array
    {
        $entries = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        foreach ($iterator as $item) {
            $entries[] = $item->getPathname() . ':' . ($item->isFile() ? (string) $item->getSize() : 'dir');
        }
        sort($entries, SORT_STRING);

        return $entries;
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($path);
    }
}
