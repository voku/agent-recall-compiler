<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;

final class ContinueUntilDoneAuthorityBoundaryTest extends TestCase
{
    public function testAutonomousContinuationCannotSelfConfirmExternalAuthorityGates(): void
    {
        $manifest = json_decode(
            (string) file_get_contents(dirname(__DIR__) . '/skills/agent-recall-consumer/operating-prompts.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($manifest['prompts'] ?? null);
        $template = null;
        foreach ($manifest['prompts'] as $prompt) {
            if (is_array($prompt) && ($prompt['id'] ?? null) === 'continue-until-done') {
                $template = $prompt['template'] ?? null;
                break;
            }
        }

        self::assertIsString($template);
        self::assertStringContainsString('internal continuation check, not approval or external confirmation', $template);
        self::assertStringContainsString('Never satisfy a human, owner, reviewer, or accepted-risk decision by confirming it yourself', $template);
        self::assertStringContainsString('Continue automatically only while the current authority remains valid', $template);
        self::assertStringContainsString('HUMAN_DECISION_REQUIRED', $template);
        self::assertStringContainsString('approved Goal, acceptance criteria, scope or non-goals', $template);
        self::assertStringContainsString('{{done_condition}} is satisfied by observed evidence', $template);
    }
}
