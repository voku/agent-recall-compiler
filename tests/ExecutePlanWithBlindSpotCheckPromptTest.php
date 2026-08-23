<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;

final class ExecutePlanWithBlindSpotCheckPromptTest extends TestCase
{
    public function testPlanExecutionStartsWithBoundedSelfCritiqueWithoutReplanningAuthority(): void
    {
        $manifest = json_decode(
            (string) file_get_contents(dirname(__DIR__) . '/skills/agent-recall-consumer/operating-prompts.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($manifest['prompts'] ?? null);
        $level = null;
        $template = null;
        foreach ($manifest['prompts'] as $prompt) {
            if (is_array($prompt) && ($prompt['id'] ?? null) === 'execute-plan-with-blind-spot-check') {
                $level = $prompt['level'] ?? null;
                $template = $prompt['template'] ?? null;
                break;
            }
        }

        self::assertSame(1, $level);
        self::assertIsString($template);
        self::assertStringContainsString('before changing anything perform a bounded blind-spot analysis', $template);
        self::assertStringContainsString('using the provided results and current authoritative repository evidence', $template);
        self::assertStringContainsString('Do not restart discovery or replace the plan merely because another approach is imaginable', $template);
        self::assertStringContainsString('VERIFIED, INFERRED, UNKNOWN, BLOCKED, or CONTRADICTED', $template);
        self::assertStringContainsString('amend only the smallest affected plan step', $template);
        self::assertStringContainsString('preserve the approved Goal, acceptance criteria, scope, non-goals, and authority', $template);
        self::assertStringContainsString('HUMAN_DECISION_REQUIRED / BLOCKED', $template);
        self::assertStringContainsString('execute the plan immediately in bounded steps', $template);
        self::assertStringContainsString('Do not stop after the blind-spot analysis', $template);
        self::assertStringContainsString("plan's observable Done When criteria are satisfied by executed verification", $template);
    }
}
