<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentMap\Context\EditContextPlanner;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\AnalysisFingerprint;
use voku\AgentMap\Index\FileEntry;
use voku\AgentMap\Index\IndexWriter;
use voku\AgentMap\Index\MethodEntry;
use voku\AgentMap\Index\RelationEntry;
use voku\AgentMap\Index\SymbolEntry;
use voku\AgentRecallCompiler\Cli;
use voku\AgentRecallCompiler\ConstraintManifest;
use voku\AgentRecallCompiler\RecallResult;
use voku\AgentRecallCompiler\TaskBrief;
use voku\AgentRecallCompiler\Verification\VerificationArtifactWriter;
use voku\AgentRecallCompiler\Verification\VerificationPlanCompiler;

final class VerificationPlanTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/verification-plan-' . bin2hex(random_bytes(6));
        foreach ([
            'src/Contract',
            'src/Service',
            'src/Controller',
            'src/Repository',
            'tests/Service',
            'constraints/active',
            'proposals/approved',
            'proposals/applied',
            'proposals/rejected',
            'history',
        ] as $directory) {
            mkdir($this->root . '/' . $directory, 0777, true);
        }
        $this->writeSources();
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

    public function testCompilerProducesDeterministicSeparatedAndBoundedArtifacts(): void
    {
        $map = $this->map();
        $task = new TaskBrief(
            id: 'EDIT-123',
            description: 'Change UserService::save without breaking callers.',
            files: [],
            validation: ['composer test'],
            targets: ['Demo\\Service\\UserService::save'],
        );
        $context = (new EditContextPlanner())->plan($map, 'Demo\\Service\\UserService::save');
        $recall = new RecallResult(
            [],
            [],
            [],
            [new ConstraintManifest(
                id: 'constraint.phpstan.max',
                engine: 'phpstan',
                ruleIdentifier: 'project.phpstan.max',
                scope: ['src/'],
                validationCommands: ['composer phpstan'],
                sourceProposal: 'proposal.phpstan.max',
                status: 'active',
            )],
        );
        $compiler = new VerificationPlanCompiler($map);
        $writer = new VerificationArtifactWriter();

        $first = $compiler->compile($task, $context, $recall);
        $second = $compiler->compile($task, $context, $recall);
        $firstPlan = $writer->renderPlan($first);
        $firstKey = $writer->renderKey($first);

        self::assertSame($firstPlan, $writer->renderPlan($second));
        self::assertSame($firstKey, $writer->renderKey($second));
        self::assertLessThanOrEqual(5, count($first->plan->knowledgeProbes));
        self::assertNotSame([], $first->plan->omittedProbeCandidates);
        self::assertStringNotContainsString('accepted_answers', $firstPlan);
        self::assertStringContainsString('accepted_answers', $firstKey);
        self::assertStringNotContainsString('accepted_answers', $writer->renderQuestionsMarkdown($first));

        $probeIds = array_map(
            static fn ($probe): string => $probe->id,
            $first->plan->knowledgeProbes,
        );
        $answerIds = array_keys($first->key->probes);
        sort($probeIds, SORT_STRING);
        sort($answerIds, SORT_STRING);
        self::assertSame($probeIds, $answerIds);
        foreach ($first->key->probes as $answer) {
            self::assertNotSame([], $answer->acceptedAnswers);
            self::assertNotSame([], $answer->evidenceIds);
            self::assertSame(
                [],
                array_values(array_diff($answer->reconciliationStates, ['confirmed', 'semantic_enrichment', 'phpstan_resolved'])),
            );
            foreach ($answer->acceptedAnswers as $acceptedAnswer) {
                self::assertTrue(
                    str_starts_with($acceptedAnswer, 'method:') || str_contains($acceptedAnswer, '.php:'),
                    'answers must use canonical symbol IDs or canonical source locations',
                );
            }
        }

        $statements = implode("\n", array_map(
            static fn ($item): string => $item->statement,
            $first->plan->checklist,
        ));
        self::assertStringContainsString('contract', strtolower($statements));
        self::assertStringContainsString('caller', strtolower($statements));
        self::assertStringContainsString('verification context', strtolower($statements));
        self::assertStringContainsString('blind spot', strtolower($statements));
        self::assertStringContainsString('project.phpstan.max', $statements);
        self::assertStringNotContainsString('dependency must be changed', strtolower($statements));

        $gateKinds = array_map(static fn ($gate): string => $gate->kind, $first->plan->objectiveGates);
        self::assertContains('runner_exit', $gateKinds);
        self::assertContains('php_lint_changed_files', $gateKinds);
        self::assertContains('post_edit_map_fresh', $gateKinds);
        self::assertContains('target_resolvable', $gateKinds);
        self::assertContains('approved_validation_command', $gateKinds);
        self::assertContains('agent_loop_verify', $gateKinds);
        self::assertSame('1', $first->plan->generator['version']);
        self::assertStringStartsWith('sha256:', $first->plan->generator['seed_sha256']);
        self::assertSame($first->plan->mapDigest, $first->key->mapDigest);
        self::assertStringStartsWith('sha256:', $first->key->planSha256);

        $changedMap = $this->map(changed: true);
        $changedContext = (new EditContextPlanner())->plan($changedMap, 'Demo\\Service\\UserService::save');
        $changed = (new VerificationPlanCompiler($changedMap))->compile($task, $changedContext, $recall);
        self::assertNotSame($firstPlan, $writer->renderPlan($changed));
        self::assertNotSame($firstKey, $writer->renderKey($changed));
    }

    public function testCliWritesSeparatedArtifactsWithoutExecutingDeclaredCommands(): void
    {
        $mapPath = $this->root . '/map.json';
        (new IndexWriter())->write($this->map(), $mapPath, 'json');
        $sentinel = $this->root . '/must-not-exist';
        $briefPath = $this->root . '/work-brief.json';
        file_put_contents($briefPath, json_encode([
            'schema_version' => '1.0',
            'task_id' => 'EDIT-123',
            'goal' => 'Change UserService::save without leaking verifier answers.',
            'scope' => ['src/Service/UserService.php'],
            'validation' => ['touch ' . $sentinel],
            'status' => 'approved',
            'revision' => 1,
            'targets' => ['Demo\\Service\\UserService::save'],
        ], JSON_THROW_ON_ERROR));

        $first = $this->root . '/first';
        $second = $this->root . '/second';
        $baseArgs = [
            'agent-recall-compiler',
            'compile',
            '--root',
            $this->root,
            '--task-brief',
            $briefPath,
            '--map-index',
            $mapPath,
            '--map-root',
            $this->root,
            '--compilation-id',
            'compilation.EDIT-123.fixed',
        ];

        self::assertSame(0, (new Cli())->run([...$baseArgs, '--output-dir', $first]));
        self::assertSame(0, (new Cli())->run([...$baseArgs, '--output-dir', $second]));
        self::assertFileDoesNotExist($sentinel);

        foreach ([
            'system.md',
            'validation-plan.md',
            'verification-plan.json',
            'verification-key.json',
            'meta.json',
            'recall.bundle.json',
            'facts.json',
            'selection-report.json',
        ] as $file) {
            self::assertFileExists($first . '/' . $file);
            self::assertSame(
                file_get_contents($first . '/' . $file),
                file_get_contents($second . '/' . $file),
                $file . ' must replay byte-for-byte',
            );
        }

        $planJson = (string) file_get_contents($first . '/verification-plan.json');
        $keyJson = (string) file_get_contents($first . '/verification-key.json');
        $systemMd = (string) file_get_contents($first . '/system.md');
        $validationMd = (string) file_get_contents($first . '/validation-plan.md');
        self::assertStringContainsString('Repository-Knowledge Verification', $systemMd);
        self::assertStringContainsString('Declared Verification Contract', $validationMd);
        self::assertStringNotContainsString('accepted_answers', $planJson);
        self::assertStringNotContainsString('accepted_answers', $systemMd);
        self::assertStringNotContainsString('accepted_answers', $validationMd);
        self::assertStringContainsString('accepted_answers', $keyJson);

        /** @var array<string, mixed> $meta */
        $meta = json_decode((string) file_get_contents($first . '/meta.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('sha256:' . hash('sha256', $planJson), $meta['verification_plan_sha256']);
        self::assertSame('sha256:' . hash('sha256', $keyJson), $meta['verification_key_sha256']);
        self::assertIsArray($meta['verification_generator']);

        $fileOnlyOutput = $this->root . '/file-only';
        self::assertSame(0, (new Cli())->run([
            'agent-recall-compiler',
            'compile',
            '--root',
            $this->root,
            '--task',
            'FILE-ONLY',
            '--description',
            'Compile ordinary file-only recall.',
            '--file',
            'src/Service/UserService.php',
            '--output-dir',
            $fileOnlyOutput,
            '--compilation-id',
            'compilation.FILE-ONLY.fixed',
        ]));
        self::assertFileExists($fileOnlyOutput . '/system.md');
        self::assertFileDoesNotExist($fileOnlyOutput . '/verification-plan.json');
        self::assertFileDoesNotExist($fileOnlyOutput . '/verification-key.json');
    }

    private function writeSources(): void
    {
        $sources = [
            'src/Contract/UserServiceInterface.php' => <<<'PHP'
<?php
namespace Demo\Contract;
interface UserServiceInterface { public function save(): void; }
PHP,
            'src/Service/UserService.php' => <<<'PHP'
<?php
namespace Demo\Service;
final class UserService implements \Demo\Contract\UserServiceInterface
{
    public function save(): void { (new \Demo\Repository\UserRepository())->persist(); }
}
PHP,
            'src/Service/SpecialUserService.php' => <<<'PHP'
<?php
namespace Demo\Service;
final class SpecialUserService { public function save(): void {} }
PHP,
            'src/Repository/UserRepository.php' => <<<'PHP'
<?php
namespace Demo\Repository;
final class UserRepository { public function persist(): void {} }
PHP,
            'tests/Service/UserServiceTest.php' => <<<'PHP'
<?php
namespace Demo\Tests\Service;
final class UserServiceTest { public function testSave(): void {} }
PHP,
        ];
        for ($index = 1; $index <= 4; ++$index) {
            $sources['src/Controller/Caller' . $index . '.php'] = sprintf(
                "<?php\nnamespace Demo\\Controller;\nfinal class Caller%d { public function submit(): void {} }\n",
                $index,
            );
        }
        foreach ($sources as $path => $content) {
            file_put_contents($this->root . '/' . $path, $content . "\n");
        }
    }

    private function map(bool $changed = false): AgentMapIndex
    {
        $contract = new SymbolEntry(
            kind: 'interface',
            name: 'UserServiceInterface',
            fqn: 'Demo\\Contract\\UserServiceInterface',
            lineStart: 3,
            lineEnd: 3,
            methods: [new MethodEntry('save', 'public', 3, 3, abstract: true, nativeReturnType: 'void', resolvedReturnType: 'void', reconciliationStatus: 'confirmed')],
            reconciliationStatus: 'confirmed',
        );
        $service = new SymbolEntry(
            kind: 'class',
            name: 'UserService',
            fqn: 'Demo\\Service\\UserService',
            lineStart: 3,
            lineEnd: 6,
            methods: [new MethodEntry('save', 'public', 5, 5, nativeReturnType: 'void', resolvedReturnType: 'void', reconciliationStatus: 'semantic_enrichment')],
            implements: ['Demo\\Contract\\UserServiceInterface'],
            reconciliationStatus: 'semantic_enrichment',
        );
        $special = new SymbolEntry(
            kind: 'class',
            name: 'SpecialUserService',
            fqn: 'Demo\\Service\\SpecialUserService',
            lineStart: 3,
            lineEnd: 3,
            methods: [new MethodEntry('save', 'public', 3, 3, nativeReturnType: 'void', resolvedReturnType: 'void', reconciliationStatus: 'confirmed')],
            reconciliationStatus: 'confirmed',
        );
        $repository = new SymbolEntry(
            kind: 'class',
            name: 'UserRepository',
            fqn: 'Demo\\Repository\\UserRepository',
            lineStart: 3,
            lineEnd: 3,
            methods: [new MethodEntry('persist', 'public', 3, 3, nativeReturnType: 'void', resolvedReturnType: 'void', reconciliationStatus: 'confirmed')],
            reconciliationStatus: 'confirmed',
        );
        $test = new SymbolEntry(
            kind: 'class',
            name: 'UserServiceTest',
            fqn: 'Demo\\Tests\\Service\\UserServiceTest',
            lineStart: 3,
            lineEnd: 3,
            methods: [new MethodEntry('testSave', 'public', 3, 3, nativeReturnType: 'void', resolvedReturnType: 'void', reconciliationStatus: 'confirmed')],
            reconciliationStatus: 'confirmed',
        );

        $files = [
            $this->file('src/Contract/UserServiceInterface.php', 'Demo\\Contract', [$contract]),
            $this->file('src/Service/UserService.php', 'Demo\\Service', [$service]),
            $this->file('src/Service/SpecialUserService.php', 'Demo\\Service', [$special]),
            $this->file('src/Repository/UserRepository.php', 'Demo\\Repository', [$repository]),
            $this->file('tests/Service/UserServiceTest.php', 'Demo\\Tests\\Service', [$test]),
        ];
        for ($index = 1; $index <= 4; ++$index) {
            $caller = new SymbolEntry(
                kind: 'class',
                name: 'Caller' . $index,
                fqn: 'Demo\\Controller\\Caller' . $index,
                lineStart: 3,
                lineEnd: 3,
                methods: [new MethodEntry('submit', 'public', 3, 3, nativeReturnType: 'void', resolvedReturnType: 'void', reconciliationStatus: 'confirmed')],
                reconciliationStatus: 'confirmed',
            );
            $files[] = $this->file('src/Controller/Caller' . $index . '.php', 'Demo\\Controller', [$caller]);
        }

        $targetId = 'method:Demo\\Service\\UserService::save';
        $relations = [
            RelationEntry::create($targetId, 'overrides', ['method:Demo\\Contract\\UserServiceInterface::save'], 'src/Service/UserService.php', 5, 5, 'confirmed'),
            RelationEntry::create('method:Demo\\Service\\SpecialUserService::save', 'overrides', [$targetId], 'src/Service/SpecialUserService.php', 3, 3, 'phpstan_resolved'),
            RelationEntry::create('method:Demo\\Tests\\Service\\UserServiceTest::testSave', 'calls', [$targetId], 'tests/Service/UserServiceTest.php', 3, 3, 'semantic_enrichment'),
            RelationEntry::create($targetId, 'calls', ['method:Demo\\Repository\\UserRepository::persist'], 'src/Service/UserService.php', 5, 5, 'phpstan_resolved'),
            RelationEntry::create($targetId, 'calls', ['unresolved:dynamic'], 'src/Service/UserService.php', 5, 5, 'dynamic'),
            RelationEntry::create($targetId, 'calls', ['method:Demo\\Repository\\UserRepository::persist', 'unresolved:alternate'], 'src/Service/UserService.php', 5, 5, 'multiple_targets'),
            RelationEntry::create($targetId, 'calls', ['method:Demo\\Repository\\UserRepository::persist'], 'src/Service/UserService.php', 5, 5, 'structural_only'),
            RelationEntry::create($targetId, 'calls', ['method:Demo\\Repository\\UserRepository::persist'], 'src/Service/UserService.php', 5, 5, 'conflict'),
            RelationEntry::create($targetId, 'calls', ['method:Demo\\Repository\\UserRepository::persist'], 'src/Service/UserService.php', 5, 5, 'stale'),
        ];
        for ($index = 1; $index <= 4; ++$index) {
            $relations[] = RelationEntry::create(
                'method:Demo\\Controller\\Caller' . $index . '::submit',
                'calls',
                [$targetId],
                'src/Controller/Caller' . $index . '.php',
                3,
                3,
                'phpstan_resolved',
            );
        }
        if ($changed) {
            $relations[] = RelationEntry::create(
                'method:Demo\\Controller\\Caller4::submit',
                'calls',
                ['method:Demo\\Repository\\UserRepository::persist'],
                'src/Controller/Caller4.php',
                3,
                3,
                'confirmed',
            );
        }

        return new AgentMapIndex(
            schemaVersion: '2.0',
            root: $this->root,
            backend: 'phpstan+simple-parser',
            files: $files,
            relations: $relations,
            fingerprint: new AnalysisFingerprint(
                phpStanVersion: '2.1.0',
                phpStanConfigSha256: 'sha256:config',
                composerLockSha256: 'sha256:lock',
                sourceDigest: $changed ? 'sha256:changed' : 'sha256:source',
            ),
        );
    }

    /** @param list<SymbolEntry> $symbols */
    private function file(string $path, string $namespace, array $symbols): FileEntry
    {
        $hash = hash_file('sha256', $this->root . '/' . $path);
        self::assertIsString($hash);

        return new FileEntry($path, 'sha256:' . $hash, $namespace, $symbols, 'analysed');
    }
}
