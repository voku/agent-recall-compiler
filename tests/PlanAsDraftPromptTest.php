<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;

final class PlanAsDraftPromptTest extends TestCase
{
    public function testExistingPlanIsTreatedAsMinimumFloorWithoutInventingScope(): void
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
            if (is_array($prompt) && ($prompt['id'] ?? null) === 'plan-as-draft') {
                $level = $prompt['level'] ?? null;
                $template = $prompt['template'] ?? null;
                break;
            }
        }

        self::assertSame(2, $level);
        self::assertIsString($template);
        self::assertStringContainsString('treat the supplied plan as a deliberately provisional draft and minimum floor', $template);
        self::assertStringContainsString('even when it already looks polished or plausible', $template);
        self::assertStringContainsString('Do not merely restate, reformat, or cosmetically expand the supplied plan', $template);
        self::assertStringContainsString('preserve VERIFIED goals, acceptance criteria, scope, non-goals, authority, and already-completed work', $template);
        self::assertStringContainsString('KEEP, STRENGTHEN, ADD, and REJECT_OR_OUT_OF_SCOPE', $template);
        self::assertStringContainsString('repository evidence for every material addition or revision', $template);
        self::assertStringContainsString('PLAN_SUFFICIENT', $template);
        self::assertStringContainsString('do not invent backlog, commands, dependencies, architecture, or authority', $template);
    }
}
