<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentRecallCompiler\Provider\ProjectCapabilityRecallProvider;
use voku\AgentRecallCompiler\RecallRootConfig;
use voku\AgentRecallCompiler\TaskBrief;

final class ProjectCapabilityRecallProviderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-recall-project-capabilities-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/.github/workflows', 0777, true);
        file_put_contents($this->root . '/composer.json', json_encode([
            'require' => ['php' => '^8.3'],
            'require-dev' => [
                'phpunit/phpunit' => '^11.5',
                'phpstan/phpstan' => '^2.1',
                'infection/infection' => '^0.29',
            ],
            'scripts' => [
                'test' => 'phpunit',
                'phpstan' => 'phpstan analyse -c phpstan.neon.dist',
                'ci' => ['@test', '@phpstan'],
            ],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        file_put_contents($this->root . '/phpstan.neon.dist', 'parameters: []');
        file_put_contents($this->root . '/infection.json', '{}');
        file_put_contents($this->root . '/.github/workflows/ci.yml', "name: CI\n");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testProviderEmitsBoundedRepositoryToolingEvidence(): void
    {
        $provider = new ProjectCapabilityRecallProvider($this->root);
        $result = $provider->collect(
            new TaskBrief('CAP-1', 'Compile project evidence.', ['src/Parser.php']),
            new RecallRootConfig($this->root, $this->root . '/constraints'),
        );

        self::assertSame('project-capabilities', $provider->manifest()->id);
        self::assertCount(1, $result->facts);
        $payload = $result->facts[0]->payload;

        self::assertSame('php', $payload['language']);
        self::assertSame('composer', $payload['package_manager']);
        self::assertSame('^8.3', $payload['runtime_constraint']);
        self::assertSame('phpunit', $payload['composer_scripts']['test']);
        self::assertSame(['@test', '@phpstan'], $payload['composer_scripts']['ci']);
        self::assertSame('^0.29', $payload['tool_packages']['infection/infection']);
        self::assertContains('phpstan.neon.dist', $payload['config_files']);
        self::assertContains('infection.json', $payload['config_files']);
        self::assertSame(['.github/workflows/ci.yml'], $payload['ci_workflows']);
    }

    public function testProviderDoesNotInventToolCommandsWhenNoComposerScriptExists(): void
    {
        file_put_contents($this->root . '/composer.json', json_encode([
            'require' => ['php' => '^8.3'],
            'require-dev' => ['phpunit/phpunit' => '^11.5'],
        ], JSON_THROW_ON_ERROR));

        $result = (new ProjectCapabilityRecallProvider($this->root))->collect(
            new TaskBrief('CAP-2', '', []),
            new RecallRootConfig($this->root, $this->root . '/constraints'),
        );

        self::assertSame([], $result->facts[0]->payload['composer_scripts']);
        self::assertSame('^11.5', $result->facts[0]->payload['tool_packages']['phpunit/phpunit']);
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . '/' . $entry;
            is_dir($full) ? $this->removeDirectory($full) : unlink($full);
        }
        rmdir($path);
    }
}
