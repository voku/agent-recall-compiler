<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentMap\Context\ContextBlindSpot;
use voku\AgentMap\Context\EditContextPlan;
use voku\AgentMap\Context\EditContextPlanner;
use voku\AgentMap\Context\EditContextPolicy;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\AnalysisFingerprint;
use voku\AgentMap\Index\FileEntry;
use voku\AgentMap\Index\IndexWriter;
use voku\AgentMap\Index\MethodEntry;
use voku\AgentMap\Index\RelationEntry;
use voku\AgentMap\Index\SymbolEntry;
use voku\AgentRecallCompiler\Cli;
use voku\AgentRecallCompiler\RecallResult;
use voku\AgentRecallCompiler\TaskBrief;
use voku\AgentRecallCompiler\Verification\EvidenceChecklistGenerator;
use voku\AgentRecallCompiler\Verification\VerificationArtifactWriter;
use voku\AgentRecallCompiler\Verification\VerificationContextLoader;
use voku\AgentRecallCompiler\Verification\VerificationPlanCompiler;

final class VerificationReviewRegressionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/verification-review-' . bin2hex(random_bytes(6));
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

    public function testVerificationRejectsMapSnapshotDrift(): void
    {
        $mapPath = $this->root . '/map.json';
        (new IndexWriter())->write($this->map(), $mapPath, 'json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Agent map changed during recall compilation');

        (new VerificationContextLoader())->load(
            $mapPath,
            $this->root,
            new EditContextPolicy(),
            'Demo\\Service\\UserService::save',
            'sha256:not-the-compiled-map',
        );
    }

    public function testReusedOutputDirectoryDropsStaleVerificationArtifacts(): void
    {
        $mapPath = $this->root . '/map.json';
        (new IndexWriter())->write($this->map(), $mapPath, 'json');
        $briefPath = $this->root . '/work-brief.json';
        file_put_contents($briefPath, json_encode([
            'schema_version' => '1.0',
            'task_id' => 'EDIT-REUSE',
            'goal' => 'Compile target-aware verification first.',
            'scope' => ['src/Service/UserService.php'],
            'status' => 'approved',
            'revision' => 1,
            'targets' => ['Demo\\Service\\UserService::save'],
        ], JSON_THROW_ON_ERROR));
        $output = $this->root . '/output';

        self::assertSame(0, (new Cli())->run([
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
            '--output-dir',
            $output,
            '--compilation-id',
            'compilation.EDIT-REUSE.target',
        ]));
        self::assertFileExists($output . '/verification-plan.json');
        self::assertFileExists($output . '/verification-key.json');

        self::assertSame(0, (new Cli())->run([
            'agent-recall-compiler',
            'compile',
            '--root',
            $this->root,
            '--task',
            'EDIT-REUSE-FILE',
            '--description',
            'Compile file-only recall into the same directory.',
            '--file',
            'src/Service/UserService.php',
            '--output-dir',
            $output,
            '--compilation-id',
            'compilation.EDIT-REUSE.file',
        ]));

        self::assertFileDoesNotExist($output . '/verification-plan.json');
        self::assertFileDoesNotExist($output . '/verification-key.json');
        $meta = (string) file_get_contents($output . '/meta.json');
        self::assertStringNotContainsString('verification_plan_sha256', $meta);
        self::assertStringNotContainsString('verification_key_sha256', $meta);
    }

    public function testBlindSpotsAtTheSameLocationRetainDistinctChecklistItems(): void
    {
        $map = $this->map();
        $context = (new EditContextPlanner())->plan($map, 'Demo\\Service\\UserService::save');
        $collisionContext = new EditContextPlan(
            requestedTarget: $context->requestedTarget,
            resolvedTarget: $context->resolvedTarget,
            policy: $context->policy,
            slices: $context->slices,
            blindSpots: [
                new ContextBlindSpot('dynamic_call', 'First unresolved runtime branch.', null, null, []),
                new ContextBlindSpot('dynamic_call', 'Second unresolved runtime branch.', null, null, []),
            ],
            omitted: [],
            mapDigest: $context->mapDigest,
            sourceBytes: $context->sourceBytes,
        );

        $items = (new EvidenceChecklistGenerator())->generate(
            $collisionContext,
            new RecallResult([], [], []),
        );
        $blindSpotIds = [];
        $messages = [];
        foreach ($items as $item) {
            $message = $item->provenance['message'] ?? null;
            if (!is_string($message)) {
                continue;
            }
            $blindSpotIds[] = $item->id;
            $messages[] = $message;
        }

        self::assertContains('First unresolved runtime branch.', $messages);
        self::assertContains('Second unresolved runtime branch.', $messages);
        self::assertCount(2, array_unique($blindSpotIds));
    }

    public function testTopLevelTestsDirectoryReceivesVerificationProbePriority(): void
    {
        $map = $this->map();
        $task = new TaskBrief(
            id: 'EDIT-PRIORITY',
            description: 'Keep verification context inside the bounded probe budget.',
            files: [],
            targets: ['Demo\\Service\\UserService::save'],
        );
        $context = (new EditContextPlanner())->plan($map, 'Demo\\Service\\UserService::save');
        $compiled = (new VerificationPlanCompiler($map))->compile(
            $task,
            $context,
            new RecallResult([], [], []),
        );
        $key = (new VerificationArtifactWriter())->renderKey($compiled);

        self::assertStringContainsString(
            'method:Demo\\Tests\\Service\\VerificationHelper::verifySave',
            $key,
        );
        self::assertStringNotContainsString('method:Demo\\Controller\\Caller4::submit', $key);
        self::assertLessThanOrEqual(5, count($compiled->plan->knowledgeProbes));
    }

    private function writeSources(): void
    {
        $sources = [
            'src/Contract/UserServiceInterface.php' => "<?php\nnamespace Demo\\Contract;\ninterface UserServiceInterface { public function save(): void; }\n",
            'src/Service/UserService.php' => "<?php\nnamespace Demo\\Service;\nfinal class UserService implements \\Demo\\Contract\\UserServiceInterface { public function save(): void {} }\n",
            'src/Repository/UserRepository.php' => "<?php\nnamespace Demo\\Repository;\nfinal class UserRepository { public function persist(): void {} }\n",
            'tests/Service/Helper.php' => "<?php\nnamespace Demo\\Tests\\Service;\nfinal class VerificationHelper { public function verifySave(): void {} }\n",
        ];
        for ($index = 1; $index <= 4; ++$index) {
            $sources['src/Controller/Caller' . $index . '.php'] = sprintf(
                "<?php\nnamespace Demo\\Controller;\nfinal class Caller%d { public function submit(): void {} }\n",
                $index,
            );
        }
        foreach ($sources as $path => $content) {
            file_put_contents($this->root . '/' . $path, $content);
        }
    }

    private function map(): AgentMapIndex
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
            lineEnd: 3,
            methods: [new MethodEntry('save', 'public', 3, 3, nativeReturnType: 'void', resolvedReturnType: 'void', reconciliationStatus: 'semantic_enrichment')],
            implements: ['Demo\\Contract\\UserServiceInterface'],
            reconciliationStatus: 'semantic_enrichment',
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
            name: 'VerificationHelper',
            fqn: 'Demo\\Tests\\Service\\VerificationHelper',
            lineStart: 3,
            lineEnd: 3,
            methods: [new MethodEntry('verifySave', 'public', 3, 3, nativeReturnType: 'void', resolvedReturnType: 'void', reconciliationStatus: 'confirmed')],
            reconciliationStatus: 'confirmed',
        );

        $files = [
            $this->file('src/Contract/UserServiceInterface.php', 'Demo\\Contract', [$contract]),
            $this->file('src/Service/UserService.php', 'Demo\\Service', [$service]),
            $this->file('src/Repository/UserRepository.php', 'Demo\\Repository', [$repository]),
            $this->file('tests/Service/Helper.php', 'Demo\\Tests\\Service', [$test]),
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
            RelationEntry::create($targetId, 'overrides', ['method:Demo\\Contract\\UserServiceInterface::save'], 'src/Service/UserService.php', 3, 3, 'confirmed'),
            RelationEntry::create('method:Demo\\Tests\\Service\\VerificationHelper::verifySave', 'calls', [$targetId], 'tests/Service/Helper.php', 3, 3, 'semantic_enrichment'),
            RelationEntry::create($targetId, 'calls', ['method:Demo\\Repository\\UserRepository::persist'], 'src/Service/UserService.php', 3, 3, 'phpstan_resolved'),
            RelationEntry::create($targetId, 'calls', ['unresolved:dynamic'], 'src/Service/UserService.php', 3, 3, 'dynamic'),
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
                sourceDigest: 'sha256:sources',
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
