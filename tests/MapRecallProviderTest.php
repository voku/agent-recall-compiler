<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentMap\Context\EditContextPolicy;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\AnalysisFingerprint;
use voku\AgentMap\Index\FileEntry;
use voku\AgentMap\Index\IndexWriter;
use voku\AgentMap\Index\MethodEntry;
use voku\AgentMap\Index\RelationEntry;
use voku\AgentMap\Index\SymbolEntry;
use voku\AgentRecallCompiler\Compilation\RecallCompilationService;
use voku\AgentRecallCompiler\Provider\MapRecallProvider;
use voku\AgentRecallCompiler\Provider\RecallProvider;
use voku\AgentRecallCompiler\Provider\RecallProviderManifest;
use voku\AgentRecallCompiler\Provider\RecallProviderResult;
use voku\AgentRecallCompiler\RecallGuidance;
use voku\AgentRecallCompiler\RecallRootConfig;
use voku\AgentRecallCompiler\TaskBrief;

final class MapRecallProviderTest extends TestCase
{
    private string $root;
    private string $mapPath;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-recall-map-' . bin2hex(random_bytes(6));
        foreach (['src/Contract', 'src/Service', 'src/Controller', 'src/Repository', 'src/Entity', 'tests/Service', 'constraints/active'] as $directory) {
            mkdir($this->root . '/' . $directory, 0777, true);
        }
        $this->writeSources();
        $this->mapPath = $this->root . '/map.json';
        (new IndexWriter())->write($this->map(), $this->mapPath, 'json');
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

    public function testProviderUsesAgentMapPlannerAndHostRootOverride(): void
    {
        $result = (new MapRecallProvider($this->mapPath, $this->root))->collect(
            new TaskBrief('TASK-1', 'Reject inactive users.', [], targets: ['Demo\\Service\\UserService::save']),
            new RecallRootConfig($this->root, $this->root . '/constraints/active'),
        );

        $editFact = null;
        foreach ($result->facts as $fact) {
            if ($fact->type === 'edit_context') {
                $editFact = $fact;
                break;
            }
        }

        self::assertNotNull($editFact);
        self::assertSame('Demo\\Service\\UserService::save', $editFact->payload['target']['resolved']);
        $byRole = [];
        foreach ($editFact->payload['slices'] as $slice) {
            foreach ($slice['roles'] as $role) {
                $byRole[$role][] = $slice['path'];
            }
        }
        self::assertContains('src/Service/UserService.php', $byRole['primary']);
        self::assertContains('src/Contract/UserServiceInterface.php', $byRole['contract']);
        self::assertContains('src/Controller/UserController.php', $byRole['change_candidate']);
        self::assertContains('tests/Service/UserServiceTest.php', $byRole['verification']);
        self::assertContains('src/Repository/UserRepository.php', $byRole['dependency']);
        self::assertContains('src/Entity/User.php', $byRole['type_definition']);
        self::assertStringContainsString('repository->persist', $this->sliceContent($editFact->payload['slices'], 'src/Service/UserService.php'));
        self::assertStringStartsWith('sha256:', $result->sourceDigest);
    }

    public function testProviderNarrowsThePrimarySliceAroundAnEditFocus(): void
    {
        $result = (new MapRecallProvider(
            $this->mapPath,
            $this->root,
            new EditContextPolicy(
                focusTerms: ['$this->repository->persist'],
                focusContextLines: 0,
                includeRelatedContext: false,
            ),
        ))->collect(
            new TaskBrief('TASK-1', 'Replace the deprecated repository call.', [], targets: ['Demo\\Service\\UserService::save']),
            new RecallRootConfig($this->root, $this->root . '/constraints/active'),
        );

        $editFact = null;
        foreach ($result->facts as $fact) {
            if ($fact->type === 'edit_context') {
                $editFact = $fact;
                break;
            }
        }

        self::assertNotNull($editFact);
        self::assertSame(
            "        \$this->repository->persist(\$user);\n",
            $this->sliceContent($editFact->payload['slices'], 'src/Service/UserService.php'),
        );
        self::assertCount(1, $editFact->payload['slices']);
    }

    public function testCompilationSelectsGuidanceForDerivedEditScopeButNotDependencies(): void
    {
        $guidanceProvider = new class implements RecallProvider {
            public function manifest(): RecallProviderManifest
            {
                return new RecallProviderManifest('test-guidance', '1.0', []);
            }

            public function collect(TaskBrief $task, RecallRootConfig $rootConfig): RecallProviderResult
            {
                return new RecallProviderResult(
                    'sha256:test-guidance',
                    activeGuidance: [
                        new RecallGuidance('guidance.controller', 'ADD', 'skill', 'controller', ['src/Controller/'], null, 'Adapt direct callers.', 'reason', null, [], 'approved'),
                        new RecallGuidance('guidance.repository', 'ADD', 'skill', 'repository', ['src/Repository/'], null, 'Change dependencies too.', 'reason', null, [], 'approved'),
                    ],
                );
            }
        };

        $compilation = (new RecallCompilationService([
            new MapRecallProvider($this->mapPath, $this->root),
            $guidanceProvider,
        ]))->compile(
            new TaskBrief('TASK-1', 'Reject inactive users.', [], targets: ['Demo\\Service\\UserService::save']),
            new RecallRootConfig($this->root, $this->root . '/constraints/active'),
        );

        self::assertSame(['guidance.controller'], array_map(static fn (RecallGuidance $guidance): string => $guidance->id, $compilation->result->selectedGuidance));
        self::assertContains('src/Controller/UserController.php', $compilation->effectiveTask->files);
        self::assertNotContains('src/Repository/UserRepository.php', $compilation->effectiveTask->files);
        self::assertSame([], $compilation->bundle['task']['files']);
        self::assertContains('src/Controller/UserController.php', $compilation->bundle['effective_scope']['derived_files']);
    }

    /** @param list<array<string, mixed>> $slices */
    private function sliceContent(array $slices, string $path): string
    {
        foreach ($slices as $slice) {
            if (($slice['path'] ?? null) === $path) {
                return is_string($slice['content'] ?? null) ? $slice['content'] : '';
            }
        }

        return '';
    }

    private function writeSources(): void
    {
        file_put_contents($this->root . '/src/Contract/UserServiceInterface.php', <<<'PHP'
<?php

namespace Demo\Contract;

interface UserServiceInterface
{
    public function save(\Demo\Entity\User $user): void;
}
PHP);
        file_put_contents($this->root . '/src/Service/UserService.php', <<<'PHP'
<?php

namespace Demo\Service;

final class UserService implements \Demo\Contract\UserServiceInterface
{
    public function __construct(private \Demo\Repository\UserRepository $repository)
    {
    }

    public function save(\Demo\Entity\User $user): void
    {
        $this->repository->persist($user);
    }
}
PHP);
        file_put_contents($this->root . '/src/Controller/UserController.php', <<<'PHP'
<?php

namespace Demo\Controller;

final class UserController
{
    public function submit(\Demo\Service\UserService $service, \Demo\Entity\User $user): void
    {
        $service->save($user);
    }
}
PHP);
        file_put_contents($this->root . '/src/Repository/UserRepository.php', <<<'PHP'
<?php

namespace Demo\Repository;

final class UserRepository
{
    public function persist(\Demo\Entity\User $user): void
    {
    }
}
PHP);
        file_put_contents($this->root . '/src/Entity/User.php', <<<'PHP'
<?php

namespace Demo\Entity;

final class User
{
}
PHP);
        file_put_contents($this->root . '/tests/Service/UserServiceTest.php', <<<'PHP'
<?php

namespace Demo\Tests\Service;

final class UserServiceTest
{
    public function testSave(\Demo\Service\UserService $service, \Demo\Entity\User $user): void
    {
        $service->save($user);
    }
}
PHP);
    }

    private function map(): AgentMapIndex
    {
        $interface = new SymbolEntry(
            kind: 'interface',
            name: 'UserServiceInterface',
            fqn: 'Demo\\Contract\\UserServiceInterface',
            lineStart: 5,
            lineEnd: 8,
            methods: [new MethodEntry('save', 'public', 7, 7, abstract: true, nativeReturnType: 'void', resolvedReturnType: 'void', reconciliationStatus: 'confirmed')],
            reconciliationStatus: 'confirmed',
        );
        $service = new SymbolEntry(
            kind: 'class',
            name: 'UserService',
            fqn: 'Demo\\Service\\UserService',
            lineStart: 5,
            lineEnd: 15,
            methods: [
                new MethodEntry('__construct', 'public', 7, 9, reconciliationStatus: 'confirmed'),
                new MethodEntry('save', 'public', 11, 14, nativeReturnType: 'void', resolvedReturnType: 'void', reconciliationStatus: 'confirmed'),
            ],
            implements: ['Demo\\Contract\\UserServiceInterface'],
            reconciliationStatus: 'confirmed',
        );
        $controller = new SymbolEntry(
            kind: 'class',
            name: 'UserController',
            fqn: 'Demo\\Controller\\UserController',
            lineStart: 5,
            lineEnd: 10,
            methods: [new MethodEntry('submit', 'public', 7, 10, nativeReturnType: 'void', resolvedReturnType: 'void', reconciliationStatus: 'confirmed')],
            reconciliationStatus: 'confirmed',
        );
        $repository = new SymbolEntry(
            kind: 'class',
            name: 'UserRepository',
            fqn: 'Demo\\Repository\\UserRepository',
            lineStart: 5,
            lineEnd: 9,
            methods: [new MethodEntry('persist', 'public', 7, 9, nativeReturnType: 'void', resolvedReturnType: 'void', reconciliationStatus: 'confirmed')],
            reconciliationStatus: 'confirmed',
        );
        $user = new SymbolEntry('class', 'User', 'Demo\\Entity\\User', 5, 7, reconciliationStatus: 'confirmed');
        $test = new SymbolEntry(
            kind: 'class',
            name: 'UserServiceTest',
            fqn: 'Demo\\Tests\\Service\\UserServiceTest',
            lineStart: 5,
            lineEnd: 10,
            methods: [new MethodEntry('testSave', 'public', 7, 10, nativeReturnType: 'void', resolvedReturnType: 'void', reconciliationStatus: 'confirmed')],
            reconciliationStatus: 'confirmed',
        );

        $files = [
            $this->file('src/Contract/UserServiceInterface.php', 'Demo\\Contract', [$interface]),
            $this->file('src/Service/UserService.php', 'Demo\\Service', [$service]),
            $this->file('src/Controller/UserController.php', 'Demo\\Controller', [$controller]),
            $this->file('src/Repository/UserRepository.php', 'Demo\\Repository', [$repository]),
            $this->file('src/Entity/User.php', 'Demo\\Entity', [$user]),
            $this->file('tests/Service/UserServiceTest.php', 'Demo\\Tests\\Service', [$test]),
        ];

        return new AgentMapIndex(
            schemaVersion: '2.0',
            root: '/container/project',
            backend: 'phpstan+simple-parser',
            files: $files,
            relations: [
                RelationEntry::create('method:Demo\\Service\\UserService::save', 'overrides', ['method:Demo\\Contract\\UserServiceInterface::save'], 'src/Service/UserService.php', 11, 11, 'confirmed'),
                RelationEntry::create('method:Demo\\Controller\\UserController::submit', 'calls', ['method:Demo\\Service\\UserService::save'], 'src/Controller/UserController.php', 9, 9, 'phpstan_resolved'),
                RelationEntry::create('method:Demo\\Tests\\Service\\UserServiceTest::testSave', 'calls', ['method:Demo\\Service\\UserService::save'], 'tests/Service/UserServiceTest.php', 9, 9, 'phpstan_resolved'),
                RelationEntry::create('method:Demo\\Service\\UserService::save', 'calls', ['method:Demo\\Repository\\UserRepository::persist'], 'src/Service/UserService.php', 13, 13, 'phpstan_resolved'),
                RelationEntry::create('method:Demo\\Service\\UserService::save', 'references_type', ['class:Demo\\Entity\\User'], 'src/Service/UserService.php', 11, 11, 'phpstan_resolved'),
            ],
            fingerprint: new AnalysisFingerprint('2.2.0', 'sha256:config', 'sha256:lock', 'sha256:sources'),
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
