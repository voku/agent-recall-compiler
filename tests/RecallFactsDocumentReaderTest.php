<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentRecallCompiler\Output\RecallFactsDocumentReader;

/** @internal */
final class RecallFactsDocumentReaderTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/recall-facts-' . bin2hex(random_bytes(6)) . '.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testMissingDocumentIsAbsent(): void
    {
        self::assertNull((new RecallFactsDocumentReader())->read($this->path));
    }

    public function testReadsBundleIdentityAndTypedFacts(): void
    {
        $digest = str_repeat('a', 64);
        file_put_contents($this->path, json_encode([
            'schema_version' => '1.0',
            'bundle_sha256' => $digest,
            'facts' => [[
                'type' => 'operating_prompt',
                'source_ref' => 'skills/prompts.json',
                'scope' => ['src/Foo.php'],
                'payload' => [
                    'prompt_id' => 'adversarial-review',
                    'level' => 2,
                    'arguments' => ['minimum_failure_modes' => 3],
                ],
            ]],
        ], JSON_THROW_ON_ERROR));

        $document = (new RecallFactsDocumentReader())->read($this->path);

        self::assertNotNull($document);
        self::assertSame($this->path, $document->identityPath);
        self::assertSame($digest, $document->bundleSha256);
        self::assertCount(1, $document->facts);
        self::assertSame('operating_prompt', $document->facts[0]->type);
        self::assertSame('skills/prompts.json', $document->facts[0]->sourceRef);
        self::assertSame(['src/Foo.php'], $document->facts[0]->scope);
        self::assertSame('adversarial-review', $document->facts[0]->payload['prompt_id']);
    }

    public function testMalformedBundleIdentityFailsLoudly(): void
    {
        file_put_contents($this->path, json_encode([
            'schema_version' => '1.0',
            'bundle_sha256' => 'sha256:' . str_repeat('a', 64),
            'facts' => [],
        ], JSON_THROW_ON_ERROR));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('canonical bundle_sha256');

        (new RecallFactsDocumentReader())->read($this->path);
    }

    public function testFactsMustBeAList(): void
    {
        file_put_contents($this->path, json_encode([
            'schema_version' => '1.0',
            'bundle_sha256' => str_repeat('b', 64),
            'facts' => ['not' => 'a list'],
        ], JSON_THROW_ON_ERROR));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('requires a facts list');

        (new RecallFactsDocumentReader())->read($this->path);
    }

    public function testListShapedRootFailsLoudly(): void
    {
        file_put_contents($this->path, '[]');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must decode to an object');

        (new RecallFactsDocumentReader())->read($this->path);
    }
}
