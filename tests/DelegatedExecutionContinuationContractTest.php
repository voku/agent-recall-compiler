<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentRecallCompiler\Rendering\OperatingPromptRenderer;

final class DelegatedExecutionContinuationContractTest extends TestCase
{
    public function testL1AndGeneratedL1ShareOneContinuationContract(): void
    {
        $markdown = (new OperatingPromptRenderer())->render([
            $this->fact('production-ready-handoff', 2, 'Create a production-ready handoff.'),
            $this->fact('continue-until-done', 1, 'Continue until observed done evidence exists.'),
        ]);

        self::assertSame(2, substr_count($markdown, 'When the authorized work contains multiple TODOs or milestones'));
        self::assertSame(2, substr_count($markdown, 'internal continuation check'));
        self::assertSame(2, substr_count($markdown, 'PRE_EXISTING'));
        self::assertSame(2, substr_count($markdown, 'NOT_READY_TO_DELEGATE'));
        self::assertSame(2, substr_count($markdown, 'executor completion prose as a claim'));
        self::assertStringContainsString('Keep every direct L1 contract below unchanged', $markdown);
    }

    public function testSharedContinuationKeepsLocalBlockersLocalButFinalSuccessStrict(): void
    {
        $markdown = (new OperatingPromptRenderer())->render([
            $this->fact('continue-until-done', 1, 'Execute bounded work.'),
        ]);

        $localBlocker = strpos($markdown, 'A discovered blocker stops only the affected slice');
        $finalEvidence = strpos($markdown, 'Before final success, reconcile it with available authoritative artifacts and evidence');

        self::assertIsInt($localBlocker);
        self::assertIsInt($finalEvidence);
        self::assertLessThan($finalEvidence, $localBlocker);
        self::assertStringContainsString('unless every remaining safe slice is transitively blocked', $markdown);
        self::assertStringContainsString('unresolved required gates still prevent final success', $markdown);
    }

    public function testProductionReadyHandoffCannotHideKnownExternalPrerequisiteFailure(): void
    {
        $markdown = (new OperatingPromptRenderer())->render([
            $this->fact('production-ready-handoff', 2, 'Create a handoff from current evidence.'),
        ]);

        self::assertStringContainsString('required hard prerequisite is missing', $markdown);
        self::assertStringContainsString('worker is not authorized to satisfy it', $markdown);
        self::assertStringContainsString('NOT_READY_TO_DELEGATE', $markdown);
        self::assertStringContainsString('verification probe', $markdown);
        self::assertStringContainsString('current evidence', $markdown);
    }

    /** @return array<string, mixed> */
    private function fact(string $id, int $level, string $content): array
    {
        return [
            'type' => 'operating_prompt',
            'source_ref' => 'operating-prompts.json#' . $id,
            'payload' => [
                'prompt_id' => $id,
                'level' => $level,
                'content' => $content,
            ],
        ];
    }
}
