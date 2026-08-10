<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\FileEntry;
use voku\AgentMap\Index\IndexWriter;
use voku\AgentMap\Index\MethodEntry;
use voku\AgentMap\Index\RelationEntry;
use voku\AgentMap\Index\SymbolEntry;
use voku\AgentRecallCompiler\Provider\MapRecallProvider;
use voku\AgentRecallCompiler\Provider\RecallFact;
use voku\AgentRecallCompiler\RecallRootConfig;
use voku\AgentRecallCompiler\TaskBrief;

final class MapArchitectureDiscoveryRecallTest extends TestCase
{
    private string $root;
    private string $mapPath;
    private string $frontSource;
    private string $serviceSource;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-recall-discovery-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/legacy/ui', 0o775, true);
        mkdir($this->root . '/legacy/domain', 0o775, true);
        mkdir($this->root . '/constraints', 0o775, true);

        $this->frontSource = "<?php\n\nfinal class Front\n{\n    public function handle(): void {}\n}\n";
        $this->serviceSource = "<?php\n\nfinal class Service\n{\n    public function run(): void {}\n}\n";
        file_put_contents($this->root . '/legacy/ui/Front.php', $this->frontSource);
        file_put_contents($this->root . '/legacy/domain/Service.php', $this->serviceSource);

        $front = new SymbolEntry(
            'class',
            'Front',
            'Front',
            3,
            6,
            [new MethodEntry('handle', 'public', 5, 5)],
        );
        $service = new SymbolEntry(
            'class',
            'Service',
            'Service',
            3,
            6,
            [new MethodEntry('run', 'public', 5, 5)],
        );

        $map = new AgentMapIndex(
            '2.0',
            $this->root,
            'test',
            [
                new FileEntry('legacy/ui/Front.php', 'sha256:' . hash('sha256', $this->frontSource), '', [$front]),
                new FileEntry('legacy/domain/Service.php', 'sha256:' . hash('sha256', $this->serviceSource), '', [$service]),
            ],
            [
                RelationEntry::create(
                    'method:Front::handle',
                    'calls',
                    ['method:Service::run'],
                    'legacy/ui/Front.php',
                    5,
                    5,
                    'phpstan_resolved',
                ),
            ],
        );

        $this->mapPath = $this->root . '/map.json';
        (new IndexWriter())->write($map, $this->mapPath, 'json');
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

    public function testUnderSpecifiedTaskGetsEvidenceBackedArchitectureDiscovery(): void
    {
        $provider = new MapRecallProvider($this->mapPath, $this->root);
        $result = $provider->collect(
            new TaskBrief('TASK-DISCOVERY', 'Find the relevant architecture before changing the request flow.', []),
            new RecallRootConfig($this->root, $this->root . '/constraints'),
        );

        $fact = $this->discoveryFact($result->facts);
        self::assertNotNull($fact);
        self::assertSame('2.1', $provider->manifest()->contractVersion);
        self::assertSame('ready', $fact->payload['status'] ?? null);
        self::assertSame([], $fact->payload['namespace_coupling'] ?? null);
        self::assertContains([
            'from' => 'legacy/ui',
            'to' => 'legacy/domain',
            'links' => 1,
            'uncertain_links' => 0,
        ], $fact->payload['directory_coupling'] ?? []);
        self::assertContains([
            'from' => 'legacy/ui/Front.php',
            'to' => 'legacy/domain/Service.php',
            'links' => 1,
            'uncertain_links' => 0,
        ], $fact->payload['file_coupling'] ?? []);
    }

    public function testExplicitFilesDoNotAddRepositoryWideDiscoveryNoise(): void
    {
        $result = (new MapRecallProvider($this->mapPath, $this->root))->collect(
            new TaskBrief('TASK-FILE', 'Inspect the known front controller.', ['legacy/ui/Front.php']),
            new RecallRootConfig($this->root, $this->root . '/constraints'),
        );

        self::assertNull($this->discoveryFact($result->facts));
    }

    public function testStaleMapProducesDiscoveryStatusInsteadOfStaleArchitecture(): void
    {
        file_put_contents($this->root . '/legacy/ui/Front.php', $this->frontSource . "\n// changed\n");

        $result = (new MapRecallProvider($this->mapPath, $this->root))->collect(
            new TaskBrief('TASK-STALE', 'Orient in the repository before making a broad change.', []),
            new RecallRootConfig($this->root, $this->root . '/constraints'),
        );

        $fact = $this->discoveryFact($result->facts);
        self::assertNotNull($fact);
        self::assertSame('stale', $fact->payload['status'] ?? null);
        self::assertArrayHasKey('legacy/ui/Front.php', $fact->payload['stale_files'] ?? []);
        self::assertArrayNotHasKey('directory_coupling', $fact->payload);
    }

    /** @param list<RecallFact> $facts */
    private function discoveryFact(array $facts): ?RecallFact
    {
        foreach ($facts as $fact) {
            if ($fact->type === 'architecture_discovery') {
                return $fact;
            }
        }

        return null;
    }
}
