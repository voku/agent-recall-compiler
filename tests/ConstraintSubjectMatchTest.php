<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use Closure;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentRecallCompiler\Cli;
use voku\AgentRecallCompiler\ConstraintManifest;
use voku\AgentRecallCompiler\ExclusionReason;
use voku\AgentRecallCompiler\RecallDecisionEngine;
use voku\AgentRecallCompiler\SelectionReason;
use voku\AgentRecallCompiler\TaskBrief;
use voku\AgentRecallCompiler\TaskFileContentReader;

/**
 * A constraint with a broad directory scope used to be selected for every task below that directory, whatever the
 * task was about. `subject_patterns` names what the rule is about, so only tasks that actually touch it get it.
 */
final class ConstraintSubjectMatchTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/recall-subject-match-' . bin2hex(random_bytes(6));
        foreach (['constraints/active', 'proposals/approved', 'proposals/applied', 'src/Auth'] as $directory) {
            if (!mkdir($this->root . '/' . $directory, 0o775, true) && !is_dir($this->root . '/' . $directory)) {
                throw new RuntimeException('Unable to create fixture root.');
            }
        }
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testATaskThatNeverTouchesTheSubjectDoesNotGetTheConstraint(): void
    {
        $result = $this->decide(
            [$this->constraint(['legacyQuery('])],
            ['src/Billing/Invoice.php'],
            ['src/Billing/Invoice.php' => 'return $this->repository->find($id);'],
        );

        self::assertSame([], $this->selectedIds($result));
        $evaluated = $result->evaluatedGuidance[0];
        self::assertFalse($evaluated->selected);
        self::assertSame(ExclusionReason::NO_SUBJECT_MATCH, $evaluated->exclusionReason);
    }

    public function testATouchedFileThatContainsTheSubjectSelectsTheConstraintAndNamesThatFile(): void
    {
        $result = $this->decide(
            [$this->constraint(['legacyQuery('])],
            ['src/Billing/Invoice.php', 'src/Billing/Customer.php'],
            [
                'src/Billing/Invoice.php' => 'return $this->repository->find($id);',
                'src/Billing/Customer.php' => '$row = legacyQuery($sql);',
            ],
        );

        self::assertSame(['constraint.test.rule'], $this->selectedIds($result));
        $evaluated = $result->evaluatedGuidance[0];
        self::assertSame(SelectionReason::SUBJECT_MATCH, $evaluated->selectionReason);
        self::assertSame(['src/Billing/Customer.php'], $evaluated->taskFiles);
    }

    public function testTheTaskTextCanNameTheSubjectWithoutAnyFileBeingRead(): void
    {
        $reader = static function (string $path): never {
            throw new RuntimeException('must not read ' . $path);
        };

        $result = (new RecallDecisionEngine())->decide(
            new TaskBrief('T-1', 'Replace the legacyQuery( helper in the billing code.', ['src/Billing/Invoice.php']),
            [],
            [],
            [],
            [$this->constraint(['legacyQuery('])],
            [],
            $reader,
        );

        self::assertSame(['constraint.test.rule'], $this->selectedIds($result));
        self::assertSame(SelectionReason::SUBJECT_MATCH, $result->evaluatedGuidance[0]->selectionReason);
    }

    public function testWithoutAReaderTheConstraintStaysSelectedAsBefore(): void
    {
        $result = (new RecallDecisionEngine())->decide(
            new TaskBrief('T-1', '', ['src/Billing/Invoice.php']),
            [],
            [],
            [],
            [$this->constraint(['legacyQuery('])],
        );

        self::assertSame(['constraint.test.rule'], $this->selectedIds($result));
        self::assertSame(SelectionReason::CONSTRAINT_SCOPE, $result->evaluatedGuidance[0]->selectionReason);
    }

    public function testAFileThatCannotBeReadKeepsTheConstraintSelected(): void
    {
        // A file the task is about to create has no content yet; the constraint may well apply to the new code.
        $result = $this->decide(
            [$this->constraint(['legacyQuery('])],
            ['src/Billing/Invoice.php', 'src/Billing/NewFile.php'],
            ['src/Billing/Invoice.php' => 'return 1;', 'src/Billing/NewFile.php' => null],
        );

        self::assertSame(['constraint.test.rule'], $this->selectedIds($result));
        self::assertSame(SelectionReason::CONSTRAINT_SCOPE, $result->evaluatedGuidance[0]->selectionReason);
    }

    public function testAConstraintWithoutSubjectPatternsIgnoresFileContent(): void
    {
        $result = $this->decide(
            [$this->constraint([])],
            ['src/Billing/Invoice.php'],
            ['src/Billing/Invoice.php' => 'return 1;'],
        );

        self::assertSame(['constraint.test.rule'], $this->selectedIds($result));
        self::assertSame(SelectionReason::CONSTRAINT_SCOPE, $result->evaluatedGuidance[0]->selectionReason);
    }

    public function testASubjectDoesNotWidenAScopeThatDoesNotMatch(): void
    {
        $result = $this->decide(
            [$this->constraint(['legacyQuery('], scope: ['src/Auth/'])],
            ['src/Billing/Invoice.php'],
            ['src/Billing/Invoice.php' => '$row = legacyQuery($sql);'],
        );

        self::assertSame([], $this->selectedIds($result));
        self::assertSame(ExclusionReason::NO_SCOPE_OVERLAP, $result->evaluatedGuidance[0]->exclusionReason);
    }

    public function testASubjectExcludedConstraintNeverBlocksOnItsOwnIncompleteness(): void
    {
        // Selecting a constraint without validation commands blocks the compilation; one the task is not about must not.
        $constraint = new ConstraintManifest('constraint.test.rule', 'phpcs', 'rule.test', ['src/'], [], 'proposal.2026-01-01.001', 'active', [], ['legacyQuery(']);

        $result = $this->decide([$constraint], ['src/Billing/Invoice.php'], ['src/Billing/Invoice.php' => 'return 1;']);

        self::assertSame([], $this->selectedIds($result));
    }

    public function testATagMatchedConstraintIsGatedOnTheTaskFiles(): void
    {
        $constraint = new ConstraintManifest('constraint.test.rule', 'phpcs', 'rule.test', ['other/'], ['vendor/bin/phpcs'], 'proposal.2026-01-01.001', 'active', ['sql'], ['legacyQuery(']);
        $task = new TaskBrief('T-1', '', ['src/Billing/Invoice.php'], tags: ['sql']);
        $reader = $this->reader(['src/Billing/Invoice.php' => 'return 1;']);

        $none = (new RecallDecisionEngine())->decide($task, [], [], [], [$constraint], [], $reader);
        $match = (new RecallDecisionEngine())->decide($task, [], [], [], [$constraint], [], $this->reader(['src/Billing/Invoice.php' => 'legacyQuery($x);']));

        self::assertSame([], $this->selectedIds($none));
        self::assertSame(['constraint.test.rule'], $this->selectedIds($match));
    }

    public function testTheReaderReturnsFilesAndDirectoriesInsideTheProjectOnly(): void
    {
        file_put_contents($this->root . '/src/Auth/Login.php', 'login();');
        file_put_contents($this->root . '/src/Auth/Logout.php', 'logout();');
        file_put_contents($this->root . '/outside.txt', 'outside');
        $project = $this->root . '/src';
        $read = new TaskFileContentReader($project);

        self::assertSame('login();', $read('Auth/Login.php'));
        self::assertStringContainsString('logout();', (string) $read('Auth'));
        self::assertStringContainsString('login();', (string) $read('Auth'));
        self::assertNull($read('Auth/Missing.php'), 'a file that does not exist yet is unknown, not empty');
        self::assertNull($read('../outside.txt'), 'a path leaving the project root is refused');
        self::assertNull($read(''));
    }

    public function testTheReaderRefusesASymlinkThatLeavesTheProject(): void
    {
        file_put_contents($this->root . '/outside.txt', 'outside');
        $project = $this->root . '/src';
        if (!@symlink($this->root . '/outside.txt', $project . '/link.txt')) {
            self::markTestSkipped('symlinks are not available here');
        }

        self::assertNull((new TaskFileContentReader($project))('link.txt'));
    }

    public function testTheReaderGivesUpOnOversizedInputInsteadOfCrawling(): void
    {
        $big = $this->root . '/src/big';
        mkdir($big);
        for ($i = 0; $i <= TaskFileContentReader::MAX_DIRECTORY_FILES; ++$i) {
            file_put_contents($big . '/f' . $i . '.txt', 'x');
        }
        file_put_contents($this->root . '/src/huge.txt', str_repeat('a', TaskFileContentReader::MAX_FILE_BYTES + 1));
        $read = new TaskFileContentReader($this->root . '/src');

        self::assertNull($read('big'), 'too many files');
        self::assertNull($read('huge.txt'), 'file larger than the cap');
    }

    public function testManifestPatternsReachTheBundleAndMetaWithTheRealSelectionReason(): void
    {
        $this->writeConstraint(['legacyQuery(']);
        file_put_contents($this->root . '/src/Auth/Login.php', '$row = legacyQuery($sql);');

        $outputDir = $this->compile();
        $meta = $this->decode($outputDir . '/meta.json');
        $bundle = $this->decode($outputDir . '/recall.bundle.json');

        self::assertSame('subject_match', $meta['selected_constraints'][0]['selection_reason']);
        self::assertSame(['legacyQuery('], $meta['selected_constraints'][0]['subject_patterns']);
        self::assertSame(['legacyQuery('], $bundle['selected_constraints'][0]['subject_patterns']);
    }

    public function testACompilationWithoutAMatchingSubjectOmitsTheConstraint(): void
    {
        $this->writeConstraint(['legacyQuery(']);
        file_put_contents($this->root . '/src/Auth/Login.php', 'return true;');

        $meta = $this->decode($this->compile() . '/meta.json');

        self::assertSame([], $meta['selected_constraints']);
    }

    public function testAConstraintWithoutPatternsKeepsItsPersistedShape(): void
    {
        $this->writeConstraint(null);
        file_put_contents($this->root . '/src/Auth/Login.php', 'return true;');

        $meta = $this->decode($this->compile() . '/meta.json');

        self::assertSame('constraint_scope', $meta['selected_constraints'][0]['selection_reason']);
        self::assertArrayNotHasKey('subject_patterns', $meta['selected_constraints'][0]);
    }

    public function testMalformedSubjectPatternsFailLoudly(): void
    {
        foreach (['not-a-list', [''], [1], ['a' => 'b']] as $invalid) {
            $this->writeConstraint($invalid);
            file_put_contents($this->root . '/src/Auth/Login.php', 'return true;');

            try {
                $this->compile(expectSuccess: false);
                self::fail('malformed subject_patterns must not compile');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('subject_patterns', $exception->getMessage());
            }
        }
    }

    /**
     * @param list<string> $subjectPatterns
     * @param list<string> $scope
     */
    private function constraint(array $subjectPatterns, array $scope = ['src/']): ConstraintManifest
    {
        return new ConstraintManifest('constraint.test.rule', 'phpcs', 'rule.test', $scope, ['vendor/bin/phpcs'], 'proposal.2026-01-01.001', 'active', [], $subjectPatterns);
    }

    /**
     * @param list<ConstraintManifest> $constraints
     * @param list<string>             $files
     * @param array<string, null|string> $contents
     */
    private function decide(array $constraints, array $files, array $contents): \voku\AgentRecallCompiler\RecallResult
    {
        return (new RecallDecisionEngine())->decide(new TaskBrief('T-1', '', $files), [], [], [], $constraints, [], $this->reader($contents));
    }

    /**
     * @param array<string, null|string> $contents
     *
     * @return Closure(string): ?string
     */
    private function reader(array $contents): Closure
    {
        return static fn (string $path): ?string => $contents[$path] ?? null;
    }

    /**
     * @return list<string>
     */
    private function selectedIds(\voku\AgentRecallCompiler\RecallResult $result): array
    {
        return array_map(static fn (ConstraintManifest $constraint): string => $constraint->id, $result->selectedConstraints);
    }

    private function writeConstraint(mixed $subjectPatterns): void
    {
        $data = [
            'schema_version' => '1.0',
            'id' => 'constraint.project.auth.no-direct-session-access',
            'engine' => 'phpstan',
            'rule_identifier' => 'project.auth.no-direct-session-access',
            'scope' => ['src/'],
            'validation_commands' => ['vendor/bin/phpstan analyse'],
            'source_proposal' => 'proposal.2026-06-13.001',
            'status' => 'active',
        ];
        if ($subjectPatterns !== null) {
            $data['subject_patterns'] = $subjectPatterns;
        }

        file_put_contents(
            $this->root . '/constraints/active/constraint.project.auth.no-direct-session-access.json',
            json_encode($data, JSON_THROW_ON_ERROR),
        );
    }

    private function compile(bool $expectSuccess = true): string
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
        if ($expectSuccess) {
            self::assertSame(0, $exit, 'compilation must succeed');
        }

        return $outputDir;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $path): array
    {
        $raw = file_get_contents($path);
        self::assertIsString($raw);
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
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
            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($directory);
    }
}
