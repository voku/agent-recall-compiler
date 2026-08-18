<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentRecallCompiler\Review\BlindSpotAnalysisLensBuilder;
use voku\AgentRecallCompiler\Review\BlindSpotPromptBuilder;
use voku\AgentRecallCompiler\Review\ReviewReport;

/** @internal */
final class BlindSpotAnalysisPromptContractTest extends TestCase
{
    public function testBlindSpotLensIsRepoFirstEvidenceBoundAndFalsifiable(): void
    {
        $prompt = (new BlindSpotAnalysisLensBuilder())->build();

        self::assertStringContainsString('Pattern drift', $prompt);
        self::assertStringContainsString('Intent erosion', $prompt);
        self::assertStringContainsString('Operational overconfidence', $prompt);
        self::assertStringContainsString('False failure attribution', $prompt);
        self::assertStringContainsString('Premature closure', $prompt);
        self::assertStringContainsString('at least two relevant in-repository examples', $prompt);
        self::assertStringContainsString('concrete failure chain', $prompt);
        self::assertStringContainsString('smallest falsification probe', $prompt);
        self::assertStringContainsString('missing dependency, generated asset, tool, or environment prerequisite', $prompt);
        self::assertStringContainsString('model confidence, numeric score', $prompt);
        self::assertStringContainsString('UNKNOWN or BLOCKED', $prompt);
        self::assertStringContainsString('READY FOR HUMAN CLOSE', $prompt);
        self::assertLessThan(3000, strlen($prompt));
    }

    public function testArtifactBlindSpotPromptComposesRepoFirstLensWithExistingTrustBoundary(): void
    {
        $workspace = sys_get_temp_dir() . '/agent-recall-blind-spot-lens-' . bin2hex(random_bytes(6));
        mkdir($workspace, 0o775, true);

        try {
            $prompt = (new BlindSpotPromptBuilder($workspace))->build(
                new ReviewReport('ABC-123', []),
                '.agent-recall/current',
            );
        } finally {
            rmdir($workspace);
        }

        self::assertStringStartsWith('# L2 blind-spot analysis prompt for ABC-123', $prompt);
        self::assertStringContainsString('## First-draft falsification lens', $prompt);
        self::assertStringContainsString('## Repo-first blind-spot lens', $prompt);
        self::assertStringContainsString('Treat every LLM-produced statement as a candidate claim', $prompt);
        self::assertStringContainsString('Use adversarial pre-mortem reasoning only as a hypothesis generator', $prompt);
        self::assertStringContainsString('Close readiness must be BLOCKED, NEEDS HUMAN REVIEW, or READY FOR HUMAN CLOSE.', $prompt);
    }
}
