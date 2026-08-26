<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentRecallCompiler\OperatingPromptArgument;
use voku\AgentRecallCompiler\OperatingPromptCatalog;
use voku\AgentRecallCompiler\OperatingPromptRecipe;
use voku\AgentRecallCompiler\OperatingPromptRequest;

final class OperatingPromptCatalogApiTest extends TestCase
{
    public function testBundledCatalogProjectsDeterministicTypedMetadata(): void
    {
        $catalog = OperatingPromptCatalog::bundled();
        $recipes = $catalog->recipes();
        $ids = array_map(static fn (OperatingPromptRecipe $recipe): string => $recipe->id, $recipes);
        $sorted = $ids;
        sort($sorted, SORT_STRING);

        self::assertSame($sorted, $ids);
        self::assertContains('discovery-first', $ids);
        self::assertContains('execute-plan-with-blind-spot-check', $ids);
        self::assertContains('reproduce-before-fix', $ids);
        self::assertContains('regression-hunt', $ids);

        $recipe = $catalog->recipe('regression-hunt');
        self::assertSame('Hunt regressions', $recipe->title);
        self::assertSame(OperatingPromptRecipe::PURPOSE_REVIEW, $recipe->purpose);
        self::assertSame(2, $recipe->level);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $recipe->templateSha256);
        self::assertCount(1, $recipe->arguments);
        self::assertSame('minimum_findings', $recipe->arguments[0]->name);
        self::assertSame(OperatingPromptArgument::TYPE_INTEGER, $recipe->arguments[0]->type);
        self::assertSame(1, $recipe->arguments[0]->minimum);
    }

    public function testPreviewIsDeterministicAndDoesNotPersistAnything(): void
    {
        $catalog = OperatingPromptCatalog::bundled();
        $request = new OperatingPromptRequest('regression-hunt', ['minimum_findings' => 3]);

        $first = $catalog->preview($request);
        $second = $catalog->preview($request);

        self::assertTrue($first->validation->valid);
        self::assertSame($first->content, $second->content);
        self::assertSame($first->templateSha256, $second->templateSha256);
        self::assertNotNull($first->content);
        self::assertStringContainsString('at least 3 concrete high-risk regression hypotheses', $first->content);
    }

    public function testValidationFailsClosedForMissingExtraWrongTypeAndBounds(): void
    {
        $catalog = OperatingPromptCatalog::bundled();

        $missing = $catalog->validate(new OperatingPromptRequest('regression-hunt'));
        self::assertFalse($missing->valid);
        self::assertSame(
            ['operating prompt regression-hunt is missing argument: minimum_findings'],
            $missing->errors,
        );

        $wrongType = $catalog->validate(new OperatingPromptRequest('regression-hunt', ['minimum_findings' => '3']));
        self::assertFalse($wrongType->valid);
        self::assertSame(
            ['operating prompt regression-hunt argument minimum_findings must be integer'],
            $wrongType->errors,
        );

        $belowMinimum = $catalog->validate(new OperatingPromptRequest('regression-hunt', ['minimum_findings' => 0]));
        self::assertFalse($belowMinimum->valid);
        self::assertSame(
            ['operating prompt regression-hunt argument minimum_findings must be >= 1'],
            $belowMinimum->errors,
        );

        $extra = $catalog->validate(new OperatingPromptRequest('reproduce-before-fix', ['unexpected' => true]));
        self::assertFalse($extra->valid);
        self::assertSame(
            ['operating prompt reproduce-before-fix received unknown argument: unexpected'],
            $extra->errors,
        );
    }

    public function testLegacyManifestWithoutPresentationMetadataPreservesScalarArgumentCompatibility(): void
    {
        $directory = sys_get_temp_dir() . '/agent-recall-catalog-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory, 0777, true));
        $manifest = $directory . '/operating-prompts.json';
        file_put_contents($manifest, json_encode([
            'schema_version' => '1.0',
            'prompts' => [[
                'id' => 'legacy-prompt',
                'level' => 1,
                'template' => 'Run {{count}} checks.',
            ]],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        try {
            $catalog = new OperatingPromptCatalog([$manifest]);
            $recipe = $catalog->recipe('legacy-prompt');
            self::assertSame(OperatingPromptRecipe::PURPOSE_UNSPECIFIED, $recipe->purpose);
            self::assertSame(OperatingPromptArgument::TYPE_SCALAR, $recipe->arguments[0]->type);
            self::assertTrue($catalog->preview(new OperatingPromptRequest('legacy-prompt', ['count' => 2]))->validation->valid);
            self::assertTrue($catalog->preview(new OperatingPromptRequest('legacy-prompt', ['count' => 'two']))->validation->valid);
        } finally {
            @unlink($manifest);
            @rmdir($directory);
        }
    }
}
