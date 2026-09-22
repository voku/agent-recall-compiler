<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;

final class MissingnessAuditPromptTest extends TestCase
{
    public function testAcceptanceToScopeGapsAreExplicitlyBlockedWithoutInventingAuthority(): void
    {
        $manifest = json_decode(
            (string) file_get_contents(dirname(__DIR__) . '/resources/skills/agent-recall-consumer/operating-prompts.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($manifest['prompts'] ?? null);
        $level = null;
        $template = null;
        foreach ($manifest['prompts'] as $prompt) {
            if (is_array($prompt) && ($prompt['id'] ?? null) === 'missingness-audit') {
                $level = $prompt['level'] ?? null;
                $template = $prompt['template'] ?? null;
                break;
            }
        }

        self::assertSame(2, $level);
        self::assertIsString($template);
        self::assertStringContainsString('Compare every material acceptance criterion or required proof against the approved source scope, test scope', $template);
        self::assertStringContainsString('BLOCKED missing-implementation-authority gap', $template);
        self::assertStringContainsString('no new source path is authorized', $template);
        self::assertStringContainsString('BLOCKED missing-verification-scope gap', $template);
        self::assertStringContainsString('approved test scope contains no adequate test anchor or authorized test path', $template);
        self::assertStringContainsString('absence never grants scope', $template);
        self::assertStringContainsString('keep UNKNOWN when evidence is insufficient to prove missingness', $template);
        self::assertStringContainsString('forbid invented backlog work, paths, architecture, or authority', $template);
    }
}
