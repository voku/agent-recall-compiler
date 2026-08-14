<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentRecallCompiler\Reflection\GuidanceGapPromptBuilder;

final class GuidanceGapAuthorityFailureModeTest extends TestCase
{
    public function testPromptDistinguishesMissingAuthorityFromUnsurfacedAuthority(): void
    {
        $prompt = (new GuidanceGapPromptBuilder())->build();

        self::assertStringContainsString('`AUTHORITY_MISSING`', $prompt);
        self::assertStringContainsString('`AUTHORITY_NOT_SURFACED`', $prompt);
        self::assertStringContainsString('`AUTHORITY_STALE`', $prompt);
        self::assertStringContainsString('`AUTHORITY_CONFLICTING`', $prompt);
        self::assertStringContainsString('`AUTHORITY_INCOMPLETE`', $prompt);
        self::assertStringContainsString('Do not guess that an unseen authority exists.', $prompt);
        self::assertStringContainsString(
            'only when evidence proves both that the authority exists and that it was omitted from the usable context',
            $prompt,
        );
        self::assertStringContainsString(
            'manifest, scope, retrieval, installation, or routing of the existing authority',
            $prompt,
        );
        self::assertLessThan(4000, strlen($prompt));
    }
}
