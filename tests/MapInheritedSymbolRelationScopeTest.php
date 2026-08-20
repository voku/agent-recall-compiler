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
use voku\AgentMap\Index\RelationEntry;
use voku\AgentMap\Index\SymbolEntry;
use voku\AgentRecallCompiler\Provider\MapRecallProvider;
use voku\AgentRecallCompiler\RecallRootConfig;
use voku\AgentRecallCompiler\TaskBrief;

/**
 * A map file entry also carries the base classes its own symbols extend, so an
 * external parent is attributed to every file inheriting it. Matching incoming
 * relations against those shared ids made each file absorb the relation graph
 * of every sibling sharing the parent.
 */
final class MapInheritedSymbolRelationScopeTest extends TestCase
{
    private const SIBLINGS = ['Alpha', 'Beta', 'Gamma'];

    private string $root;
    private string $mapPath;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-recall-inherited-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/tests', 0777, true);
        mkdir($this->root . '/constraints/active', 0777, true);
        foreach (self::SIBLINGS as $name) {
            file_put_contents($this->root . '/tests/' . $name . 'Test.php', "<?php\nclass " . $name . "Test {}\n");
        }

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

    public function testAFileFactKeepsOnlyItsOwnRelationsWhenSiblingsShareABaseClass(): void
    {
        $result = (new MapRecallProvider($this->mapPath, $this->root))->collect(
            new TaskBrief('TASK-1', 'Only Alpha is in scope.', ['tests/AlphaTest.php']),
            new RecallRootConfig($this->root, $this->root . '/constraints/active'),
        );

        $fileFact = null;
        foreach ($result->facts as $fact) {
            if ($fact->id === 'map.file.tests/AlphaTest.php') {
                $fileFact = $fact;
                break;
            }
        }

        self::assertNotNull($fileFact, 'the scoped file must produce a navigation fact');

        $relations = $fileFact->payload['relations'];
        self::assertIsArray($relations);

        $files = [];
        foreach ($relations as $relation) {
            self::assertIsArray($relation);
            $files[(string) $relation['file']] = true;
        }

        self::assertSame(
            ['tests/AlphaTest.php' => true],
            $files,
            'inheriting a shared base class must not pull in sibling relations',
        );
    }

    private function map(): AgentMapIndex
    {
        $base = new SymbolEntry(
            kind: 'class',
            name: 'FrameworkTestCase',
            fqn: 'Vendor\\Framework\\FrameworkTestCase',
            lineStart: 5,
            lineEnd: 40,
            methods: [
                new MethodEntry('assertSame', 'public', 10, 12),
                new MethodEntry('assertTrue', 'public', 14, 16),
            ],
        );

        $files = [];
        $relations = [];
        foreach (self::SIBLINGS as $name) {
            $path = 'tests/' . $name . 'Test.php';
            $own = new SymbolEntry(
                kind: 'class',
                name: $name . 'Test',
                fqn: 'Demo\\Tests\\' . $name . 'Test',
                lineStart: 5,
                lineEnd: 20,
                methods: [new MethodEntry('testItWorks', 'public', 7, 10)],
                extends: ['Vendor\\Framework\\FrameworkTestCase'],
            );

            // The real map lists the inherited parent alongside the declared class.
            $files[] = new FileEntry(
                $path,
                'sha256:' . hash_file('sha256', $this->root . '/' . $path),
                'Demo\\Tests',
                [$own, $base],
                'analysed',
            );

            $relations[] = RelationEntry::create(
                'class:Demo\\Tests\\' . $name . 'Test',
                'declares_method',
                ['method:Vendor\\Framework\\FrameworkTestCase::assertSame'],
                $path,
                7,
                10,
                'structural_only',
            );
        }

        return new AgentMapIndex(
            schemaVersion: '2.0',
            root: '/container/project',
            backend: 'phpstan+simple-parser',
            files: $files,
            relations: $relations,
            fingerprint: new AnalysisFingerprint('2.2.0', 'sha256:config', 'sha256:lock', 'sha256:sources'),
        );
    }
}
