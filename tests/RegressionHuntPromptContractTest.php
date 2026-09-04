<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;

final class RegressionHuntPromptContractTest extends TestCase
{
    public function testRegressionHuntUsesMinimumAsProbeBudgetWithoutDefectQuota(): void
    {
        $manifest = json_decode(
            (string) file_get_contents(dirname(__DIR__) . '/resources/skills/agent-recall-consumer/operating-prompts.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($manifest['prompts'] ?? null);
        $template = null;
        foreach ($manifest['prompts'] as $prompt) {
            if (is_array($prompt) && ($prompt['id'] ?? null) === 'regression-hunt') {
                $template = $prompt['template'] ?? null;
                break;
            }
        }

        self::assertIsString($template);
        self::assertStringContainsString('investigates at least {{minimum_findings}} concrete high-risk regression hypotheses', $template);
        self::assertStringContainsString('CLEAN', $template);
        self::assertStringContainsString('BLOCKED', $template);
        self::assertStringContainsString('Do not manufacture defects to satisfy {{minimum_findings}}', $template);
        self::assertStringNotContainsString('until they expose at least {{minimum_findings}}', $template);
    }
}
