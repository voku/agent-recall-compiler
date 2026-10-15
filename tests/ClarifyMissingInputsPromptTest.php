<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentRecallCompiler\BundledOperatingPromptManifest;
use voku\AgentRecallCompiler\OperatingPromptCatalog;
use voku\AgentRecallCompiler\OperatingPromptRequest;

final class ClarifyMissingInputsPromptTest extends TestCase
{
    public function testRecipeTurnsGapsIntoBoundedQuestionsWithoutInventingEvidenceOrAuthority(): void
    {
        $manifest = json_decode(
            (string) file_get_contents(BundledOperatingPromptManifest::consumer()),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($manifest['prompts'] ?? null);
        $prompt = null;
        foreach ($manifest['prompts'] as $candidate) {
            if (is_array($candidate) && ($candidate['id'] ?? null) === 'clarify-missing-inputs') {
                $prompt = $candidate;
                break;
            }
        }

        self::assertIsArray($prompt);
        self::assertSame(2, $prompt['level']);
        $template = $prompt['template'];
        self::assertIsString($template);
        self::assertStringContainsString('Ask at most {{maximum_questions}} questions', $template);
        self::assertStringContainsString('MISSING, UNKNOWN, CONTRADICTED, or HUMAN_DECISION_REQUIRED', $template);
        self::assertStringContainsString('the exact information or command output needed', $template);
        self::assertStringContainsString('Prefer resolving a gap from available evidence over asking', $template);
        self::assertStringContainsString('Never invent commands, files, paths, scope, architecture, or authority', $template);
        self::assertStringContainsString('never answer a human or owner decision on their behalf', $template);
        self::assertStringContainsString('do not implement or approve anything', $template);
    }

    public function testCatalogRequiresAPositiveQuestionLimit(): void
    {
        $catalog = OperatingPromptCatalog::bundled();

        self::assertTrue($catalog->validate(new OperatingPromptRequest('clarify-missing-inputs', ['maximum_questions' => 3]))->valid);
        self::assertSame(
            ['operating prompt clarify-missing-inputs is missing argument: maximum_questions'],
            $catalog->validate(new OperatingPromptRequest('clarify-missing-inputs', []))->errors,
        );
        self::assertSame(
            ['operating prompt clarify-missing-inputs argument maximum_questions must be >= 1'],
            $catalog->validate(new OperatingPromptRequest('clarify-missing-inputs', ['maximum_questions' => 0]))->errors,
        );
    }
}
