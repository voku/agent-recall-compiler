<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentRecallCompiler\Provider\ScopedDocumentRecallProvider;
use voku\AgentRecallCompiler\RecallRootConfig;
use voku\AgentRecallCompiler\TaskBrief;

final class ScopedDocumentSourceContainmentTest extends TestCase
{
    private string $root;

    private string $projectRoot;

    private string $siblingRoot;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-recall-document-containment-' . bin2hex(random_bytes(8));
        $this->projectRoot = $this->root . '/project';
        $this->siblingRoot = $this->root . '/sibling';
        mkdir($this->projectRoot . '/docs', 0o775, true);
        mkdir($this->siblingRoot . '/docs', 0o775, true);
        file_put_contents($this->root . '/host-secret.md', "host-only-secret\n");
    }

    protected function tearDown(): void
    {
        $this->removePath($this->root);
    }

    public function testExplicitlyAllowedSiblingRootCanBeRead(): void
    {
        file_put_contents($this->siblingRoot . '/docs/shared.md', "Cross-repository guidance.\n");
        $manifest = $this->writeManifest('../../sibling/docs/shared.md');

        $result = $this->provider($manifest)->collect(
            $this->task(),
            $this->rootConfig([$this->projectRoot, $this->siblingRoot]),
        );

        self::assertCount(1, $result->facts);
        self::assertSame('Cross-repository guidance.', $result->facts[0]->payload['content'] ?? null);
    }

    public function testParentTraversalCannotReadOutsideAllowedSourceRoots(): void
    {
        $manifest = $this->writeManifest('../../host-secret.md');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must stay inside an allowed source root');

        $this->provider($manifest)->collect(
            $this->task(),
            $this->rootConfig([$this->projectRoot, $this->siblingRoot]),
        );
    }

    public function testSymlinkCannotReadOutsideAllowedSourceRoots(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('Symlink containment regression is exercised on Unix-like CI.');
        }

        $link = $this->projectRoot . '/docs/linked-secret.md';
        self::assertTrue(symlink($this->root . '/host-secret.md', $link));
        $manifest = $this->writeManifest('linked-secret.md');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must stay inside an allowed source root');

        $this->provider($manifest)->collect(
            $this->task(),
            $this->rootConfig([$this->projectRoot, $this->siblingRoot]),
        );
    }

    public function testManifestCannotAuthorizeItsOwnAdditionalSourceRoot(): void
    {
        $manifest = $this->writeManifest(
            '../../host-secret.md',
            ['allowed_source_roots' => [$this->root]],
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must stay inside an allowed source root');

        $this->provider($manifest)->collect(
            $this->task(),
            $this->rootConfig([$this->projectRoot, $this->siblingRoot]),
        );
    }

    public function testRegularProjectDocumentStillLoads(): void
    {
        file_put_contents($this->projectRoot . '/docs/architecture.md', "Keep the boundary explicit.\n");
        $manifest = $this->writeManifest('architecture.md');

        $result = $this->provider($manifest)->collect(
            $this->task(),
            $this->rootConfig([$this->projectRoot]),
        );

        self::assertCount(1, $result->facts);
        self::assertSame('docs/architecture.md', $result->facts[0]->payload['canonical_source_ref'] ?? null);
        self::assertSame('Keep the boundary explicit.', $result->facts[0]->payload['content'] ?? null);
    }

    private function provider(string $manifest): ScopedDocumentRecallProvider
    {
        return new ScopedDocumentRecallProvider($manifest);
    }

    private function task(): TaskBrief
    {
        return new TaskBrief('SECURITY-1', 'Contain scoped documents', ['src/Foo.php']);
    }

    /** @param list<string> $allowedSourceRoots */
    private function rootConfig(array $allowedSourceRoots): RecallRootConfig
    {
        return new RecallRootConfig(
            $this->projectRoot . '/.agent-loop/recall',
            $this->projectRoot . '/.agent-loop/learning/constraints/active',
            $this->projectRoot,
            allowedSourceRoots: $allowedSourceRoots,
        );
    }

    /** @param array<string, mixed> $manifestOverrides */
    private function writeManifest(string $source, array $manifestOverrides = []): string
    {
        $path = $this->projectRoot . '/docs/manifest.json';
        $manifest = array_replace(
            [
                'schema_version' => '1.0',
                'documents' => [[
                    'id' => 'architecture',
                    'type' => 'adr',
                    'source' => $source,
                    'scope' => [],
                    'tags' => [],
                ]],
            ],
            $manifestOverrides,
        );
        file_put_contents(
            $path,
            json_encode(
                $manifest,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ) . "\n",
        );

        return $path;
    }

    private function removePath(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->removePath($path . '/' . $entry);
        }
        rmdir($path);
    }
}
