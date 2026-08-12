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
use voku\AgentMap\Search\ChunkExtractor;
use voku\AgentMap\Search\SearchIndexStore;
use voku\AgentRecallCompiler\Cli;
use voku\AgentRecallCompiler\Provider\MapRecallProvider;
use voku\AgentRecallCompiler\Provider\RecallFact;
use voku\AgentRecallCompiler\RecallPromptBuilder;
use voku\AgentRecallCompiler\RecallResult;
use voku\AgentRecallCompiler\RecallRootConfig;
use voku\AgentRecallCompiler\TaskBrief;

final class MapSearchCandidatesTest extends TestCase
{
    private string $root;
    private string $mapPath;
    private string $searchPath;

    protected function setUp(): void
    {
        if (!SearchIndexStore::supportsFts5()) {
            self::markTestSkipped('SQLite FTS5 is not available in this PHP build.');
        }

        $this->root = sys_get_temp_dir() . '/agent-recall-search-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src/Invoice', 0777, true);
        mkdir($this->root . '/src/Mail', 0777, true);
        mkdir($this->root . '/constraints/active', 0777, true);
        $this->writeSources();

        $this->mapPath = $this->root . '/map.json';
        $this->searchPath = $this->root . '/search.sqlite';
        (new IndexWriter())->write($this->map(), $this->mapPath, 'json');
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

    public function testRankedCandidatesCarryTheirChannelsAndFilePaths(): void
    {
        $this->buildSearchIndex('sha256:sources');

        $fact = $this->collectSearchFact('Dunning reminder mails are sent twice for the same overdue invoice.');

        self::assertSame('map.search.candidates', $fact->id);
        self::assertSame('navigation_candidates', $fact->type);
        self::assertSame('derived_navigation', $fact->authority);
        self::assertSame('ranked', $fact->payload['status']);
        self::assertContains('src/Mail/DunningMailer.php', $fact->scope);
        self::assertSame('sha256:sources', $fact->payload['map_snapshot']);
        self::assertSame($fact->payload['map_snapshot'], $fact->payload['search_index_snapshot']);

        $results = $fact->payload['results'];
        self::assertIsArray($results);
        self::assertNotSame([], $results);
        $paths = [];
        foreach ($results as $hit) {
            self::assertIsArray($hit);
            self::assertNotSame([], $hit['reasons']);
            $paths[] = $hit['file_path'];
        }
        self::assertContains('src/Mail/DunningMailer.php', $paths);
    }

    /**
     * Without sqlite-vec the semantic channel is missing, and a result set that hid that would make
     * a later ranking comparison meaningless.
     */
    public function testAMissingSemanticChannelIsReportedRatherThanHidden(): void
    {
        $this->buildSearchIndex('sha256:sources');

        $payload = $this->collectSearchFact('Dunning reminder mails are sent twice for the same overdue invoice.')->payload;

        if ($payload['degraded'] === true) {
            self::assertSame('semantic_channel_unavailable', $payload['degraded_reason']);
            self::assertSame('structural+lexical', $payload['effective_mode']);

            return;
        }

        self::assertNull($payload['degraded_reason']);
        self::assertSame('structural+lexical+semantic', $payload['effective_mode']);
    }

    public function testASearchIndexBuiltFromAnotherMapIsRefusedInsteadOfUsed(): void
    {
        $this->buildSearchIndex('sha256:some-older-map');

        $fact = $this->collectSearchFact('Dunning reminder mails are sent twice for the same overdue invoice.');

        self::assertSame('map.search.status', $fact->id);
        self::assertSame('stale', $fact->payload['status']);
        self::assertSame('sha256:sources', $fact->payload['map_snapshot']);
        self::assertSame('sha256:some-older-map', $fact->payload['search_index_snapshot']);
        self::assertArrayNotHasKey('results', $fact->payload);
    }

    public function testAnAbsentSearchIndexIsNamedInsteadOfSilentlySkipped(): void
    {
        $fact = $this->collectSearchFact('Dunning reminder mails are sent twice for the same overdue invoice.');

        self::assertSame('missing', $fact->payload['status']);
        self::assertStringContainsString($this->searchPath, $fact->payload['reason']);
    }

    public function testATaskLabelIsNotTreatedAsASearchQuery(): void
    {
        $this->buildSearchIndex('sha256:sources');

        $fact = $this->collectSearchFact('fix mail');

        self::assertSame('skipped', $fact->payload['status']);
    }

    public function testSearchStaysOptInAndProviderContractTracksDiscoveryAndSearchShapes(): void
    {
        $this->buildSearchIndex('sha256:sources');

        $provider = new MapRecallProvider($this->mapPath, $this->root);
        $result = $provider->collect(
            new TaskBrief('TASK-4', 'Dunning reminder mails are sent twice for the same overdue invoice.', []),
            new RecallRootConfig($this->root, $this->root . '/constraints/active'),
        );

        foreach ($result->facts as $fact) {
            self::assertNotSame('navigation_candidates', $fact->type);
        }
        self::assertSame('2.1', $provider->manifest()->contractVersion);
        self::assertSame(
            '2.2',
            (new MapRecallProvider($this->mapPath, $this->root, searchDatabase: $this->searchPath))->manifest()->contractVersion,
        );
    }

    public function testTheBriefingLabelsCandidatesAsInferredLeads(): void
    {
        $this->buildSearchIndex('sha256:sources');

        $task = new TaskBrief('TASK-5', 'Dunning reminder mails are sent twice for the same overdue invoice.', []);
        $fact = $this->collectSearchFact($task->description);
        $systemMd = (new RecallPromptBuilder())->buildSystemMd($task, '', new RecallResult([], [], []), null, [$fact->toArray()]);

        self::assertStringContainsString('## Candidate Navigation (ranked, unverified)', $systemMd);
        self::assertStringContainsString('**INFERRED**', $systemMd);
        self::assertStringContainsString('src/Mail/DunningMailer.php', $systemMd);
    }

    public function testTheBriefingNamesAStaleSearchIndexInsteadOfShowingNothing(): void
    {
        $this->buildSearchIndex('sha256:some-older-map');

        $task = new TaskBrief('TASK-6', 'Dunning reminder mails are sent twice for the same overdue invoice.', []);
        $fact = $this->collectSearchFact($task->description);
        $systemMd = (new RecallPromptBuilder())->buildSystemMd($task, '', new RecallResult([], [], []), null, [$fact->toArray()]);

        self::assertStringContainsString('**No search candidates** (stale)', $systemMd);
        self::assertStringContainsString('search-index refresh', $systemMd);
    }

    public function testCompileWiresTheSearchIndexThroughTheCli(): void
    {
        $this->buildSearchIndex('sha256:sources');
        $output = $this->root . '/out';

        self::assertSame(0, (new Cli())->run([
            'agent-recall-compiler', 'compile', '--root', $this->root,
            '--task', 'TASK-7',
            '--description', 'Dunning reminder mails are sent twice for the same overdue invoice.',
            '--map-index', $this->mapPath, '--map-root', $this->root,
            '--map-search-index', $this->searchPath, '--map-search-limit', '3',
            '--output-dir', $output, '--compilation-id', 'compilation.TASK-7.search',
        ]));

        $facts = json_decode((string) file_get_contents($output . '/facts.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($facts);
        $byId = [];
        foreach ($facts['facts'] as $fact) {
            $byId[$fact['id']] = $fact;
        }
        self::assertArrayHasKey('map.search.candidates', $byId);
        self::assertSame(3, $byId['map.search.candidates']['payload']['limit']);
        self::assertLessThanOrEqual(3, count($byId['map.search.candidates']['payload']['results']));
        self::assertStringContainsString(
            'Candidate Navigation (ranked, unverified)',
            (string) file_get_contents($output . '/system.md'),
        );
    }

    public function testCompileRefusesASearchIndexWithoutAMapIndex(): void
    {
        // A search index without a map has nothing to verify its snapshot against, so the ranking
        // could not be tied to the map it claims to describe.
        self::assertSame(1, (new Cli())->run([
            'agent-recall-compiler', 'compile', '--root', $this->root,
            '--task', 'TASK-8',
            '--description', 'Dunning reminder mails are sent twice for the same overdue invoice.',
            '--map-search-index', $this->searchPath,
            '--output-dir', $this->root . '/out-invalid',
        ]));
    }

    private function collectSearchFact(string $description): RecallFact
    {
        $result = (new MapRecallProvider($this->mapPath, $this->root, searchDatabase: $this->searchPath, searchLimit: 5))->collect(
            new TaskBrief('TASK-3', $description, []),
            new RecallRootConfig($this->root, $this->root . '/constraints/active'),
        );

        foreach ($result->facts as $fact) {
            if ($fact->type === 'navigation_candidates') {
                return $fact;
            }
        }

        self::fail('no navigation_candidates fact was emitted');
    }

    private function buildSearchIndex(string $mapSnapshot): void
    {
        $map = $this->map();
        $runtimeMap = new AgentMapIndex(
            schemaVersion: $map->schemaVersion,
            root: $this->root,
            backend: $map->backend,
            files: $map->files,
            relations: $map->relations,
            diagnostics: $map->diagnostics,
            fingerprint: $map->fingerprint,
        );

        $store = new SearchIndexStore($this->searchPath);
        $store->replaceChunks((new ChunkExtractor())->extract($runtimeMap));
        $store->setMeta('map_snapshot', $mapSnapshot);
    }

    private function writeSources(): void
    {
        file_put_contents($this->root . '/src/Mail/DunningMailer.php', <<<'PHP'
<?php

namespace Demo\Mail;

final class DunningMailer
{
    public function sendReminder(int $invoiceId): void
    {
        // Sends the overdue dunning reminder mail for one invoice.
        $this->transport->send($invoiceId, 'dunning reminder overdue');
    }
}
PHP);
        file_put_contents($this->root . '/src/Invoice/InvoiceArchive.php', <<<'PHP'
<?php

namespace Demo\Invoice;

final class InvoiceArchive
{
    public function archive(int $invoiceId): void
    {
        // Moves a settled invoice into the long term archive storage.
        $this->storage->move($invoiceId);
    }
}
PHP);
    }

    private function map(): AgentMapIndex
    {
        $mailer = new SymbolEntry(
            kind: 'class',
            name: 'DunningMailer',
            fqn: 'Demo\\Mail\\DunningMailer',
            lineStart: 5,
            lineEnd: 12,
            methods: [new MethodEntry('sendReminder', 'public', 7, 11, nativeReturnType: 'void', resolvedReturnType: 'void', reconciliationStatus: 'confirmed')],
            reconciliationStatus: 'confirmed',
        );
        $archive = new SymbolEntry(
            kind: 'class',
            name: 'InvoiceArchive',
            fqn: 'Demo\\Invoice\\InvoiceArchive',
            lineStart: 5,
            lineEnd: 12,
            methods: [new MethodEntry('archive', 'public', 7, 11, nativeReturnType: 'void', resolvedReturnType: 'void', reconciliationStatus: 'confirmed')],
            reconciliationStatus: 'confirmed',
        );

        return new AgentMapIndex(
            schemaVersion: '2.0',
            root: '/container/project',
            backend: 'phpstan+simple-parser',
            files: [
                $this->file('src/Mail/DunningMailer.php', 'Demo\\Mail', [$mailer]),
                $this->file('src/Invoice/InvoiceArchive.php', 'Demo\\Invoice', [$archive]),
            ],
            relations: [],
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
