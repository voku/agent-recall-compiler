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
        self::assertStringContainsString('1. **Goal**', $markdown);
        self::assertStringContainsString('2. **Context**', $markdown);
        self::assertStringContainsString('3. **Constraints**', $markdown);
        self::assertStringContainsString('4. **Verification**', $markdown);
        self::assertStringContainsString('5. **Done When**', $markdown);
        self::assertStringContainsString('Verification names how reality is measured', $markdown);
        self::assertStringContainsString('Done When names the acceptable observed result', $markdown);
    }
}
