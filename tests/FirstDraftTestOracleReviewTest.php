<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentRecallCompiler\Review\FirstDraftReviewPromptBuilder;

/** @internal */
final class FirstDraftTestOracleReviewTest extends TestCase
{
    public function testDefaultReviewChallengesBothProductionAndTestAssumptions(): void
    {
        $prompt = (new FirstDraftReviewPromptBuilder())->build();

        // These are deliberate prompt-contract terms, not evidence that a host
        // actually performed the proposed falsification experiments.
        self::assertStringContainsString('tests as first-draft artifacts', $prompt);
        self::assertStringContainsString('different correct implementation', $prompt);
        self::assertStringContainsString('plausible incorrect implementation', $prompt);
        self::assertStringContainsString('harmless-change probe', $prompt);
        self::assertStringContainsString('behavioral mutation', $prompt);
        self::assertStringContainsString('UNKNOWN', $prompt);
        self::assertStringContainsString('durable regression tests', $prompt);
        self::assertStringContainsString('exploratory work probes', $prompt);
        self::assertStringContainsString('sanitized probe evidence', $prompt);
    }
}
