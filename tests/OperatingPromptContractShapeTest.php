<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentRecallCompiler\Rendering\OperatingPromptRenderer;

final class OperatingPromptContractShapeTest extends TestCase
{
    public function testL2ConstructionSeparatesVerificationFromDoneWhen(): void
    {
        $markdown = (new OperatingPromptRenderer())->render([[
            'type' => 'operating_prompt',
            'source_ref' => 'operating-prompts.json#coverage-mutation',
            'payload' => [
                'prompt_id' => 'coverage-mutation',
                'level' => 2,
                'content' => 'Create a project-specific test-hardening prompt.',
            ],
        ]]);

        self::assertStringContainsString('produce exactly these five sections', $markdown);

        preg_match_all('/^([1-9]\. \*\*[^\n]+\*\*)/m', $markdown, $matches);
        self::assertSame([
            '1. **Goal**',
            '2. **Context**',
            '3. **Constraints**',
            '4. **Verification**',
            '5. **Done When**',
        ], $matches[1]);

        self::assertSame(1, substr_count($markdown, '1. **Goal**'));
        self::assertSame(1, substr_count($markdown, '2. **Context**'));
        self::assertSame(1, substr_count($markdown, '3. **Constraints**'));
        self::assertSame(1, substr_count($markdown, '4. **Verification**'));
        self::assertSame(1, substr_count($markdown, '5. **Done When**'));
        self::assertStringContainsString('Verification names how reality is measured', $markdown);
        self::assertStringContainsString('Done When names the acceptable observed result', $markdown);
        self::assertStringContainsString('observable results and stopping conditions', $markdown);
    }
}
