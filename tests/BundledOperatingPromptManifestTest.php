<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use JsonException;
use PHPUnit\Framework\TestCase;
use voku\AgentRecallCompiler\BundledOperatingPromptManifest;

final class BundledOperatingPromptManifestTest extends TestCase
{
    /** @throws JsonException */
    public function testConsumerManifestIsOwnerResolvedAndContainsDurableHandoffRecipe(): void
    {
        $path = BundledOperatingPromptManifest::consumer();

        self::assertFileExists($path);
        $contents = file_get_contents($path);
        self::assertIsString($contents);
        $manifest = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);
        self::assertSame('1.0', $manifest['schema_version'] ?? null);

        $ids = [];
        foreach ($manifest['prompts'] ?? [] as $prompt) {
            if (is_array($prompt) && is_string($prompt['id'] ?? null)) {
                $ids[] = $prompt['id'];
            }
        }

        self::assertContains('todo-card-handoff', $ids);
    }
}
