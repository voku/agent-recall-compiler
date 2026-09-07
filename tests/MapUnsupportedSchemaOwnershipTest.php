<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentMap\Context\EditContextPolicy;
use voku\AgentRecallCompiler\Provider\MapRecallProvider;
use voku\AgentRecallCompiler\RecallRootConfig;
use voku\AgentRecallCompiler\TaskBrief;
use voku\AgentRecallCompiler\Verification\VerificationContextLoader;

final class MapUnsupportedSchemaOwnershipTest extends TestCase
{
    private string $root;
    private string $mapPath;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-recall-unsupported-map-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o775, true);

        $this->mapPath = $this->root . '/map.json';
        file_put_contents($this->mapPath, "{\"schema_version\":\"1.0\"}\n");
    }

    protected function tearDown(): void
    {
        if (is_file($this->mapPath)) {
            unlink($this->mapPath);
        }
        if (is_dir($this->root)) {
            rmdir($this->root);
        }
    }

    public function testProviderLeavesUnsupportedSchemaRefusalToAgentMap(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported agent map schema version 1.0');

        (new MapRecallProvider($this->mapPath, $this->root))->collect(
            new TaskBrief('TASK-1', 'Inspect the mapped service.', ['src/Service.php']),
            new RecallRootConfig($this->root, $this->root),
        );
    }

    public function testVerificationLoaderLeavesUnsupportedSchemaRefusalToAgentMap(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported agent map schema version 1.0');

        (new VerificationContextLoader())->load(
            indexPath: $this->mapPath,
            sourceRoot: $this->root,
            policy: new EditContextPolicy(),
            target: 'Demo\\Service::run',
            expectedMapDigest: 'sha256:not-reached',
        );
    }
}
