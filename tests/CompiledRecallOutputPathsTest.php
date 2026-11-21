<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentRecallCompiler\CompileResult;
use voku\AgentRecallCompiler\Output\CompiledRecallOutputPaths;
use voku\AgentRecallCompiler\Output\CompiledRecallOutputReader;

/** @internal */
final class CompiledRecallOutputPathsTest extends TestCase
{
    public function testHostsGetEveryOwnerArtifactPathWithoutSpellingItsFilename(): void
    {
        $paths = new CompiledRecallOutputPaths('/recall/ABC-1/');

        self::assertSame('/recall/ABC-1', $paths->directory());
        self::assertSame('/recall/ABC-1/meta.json', $paths->identity());
        self::assertSame('/recall/ABC-1/system.md', $paths->briefing());
        self::assertSame('/recall/ABC-1/validation-plan.md', $paths->validationPlan());
        self::assertSame('/recall/ABC-1/facts.json', $paths->facts());
        self::assertSame('/recall/ABC-1/recall.bundle.json', $paths->bundle());
        self::assertSame('/recall/ABC-1/selection-report.json', $paths->selectionReport());
        self::assertSame('/recall/ABC-1/recall-log.draft.json', $paths->outcomeDraft());
        self::assertSame('/recall/ABC-1/feedback-assessment.draft.json', $paths->feedbackAssessmentDraft());
        self::assertSame('/recall/ABC-1/verification-plan.json', $paths->verificationPlan());
        self::assertSame('/recall/ABC-1/verification-key.json', $paths->verificationKey());
    }

    public function testExistingOwnerEntryPointsAgreeWithTheAccessor(): void
    {
        $paths = new CompiledRecallOutputPaths('/recall/ABC-1');
        $result = new CompileResult('/recall/ABC-1', 'c-1', 'sha256:' . str_repeat('a', 64));

        self::assertEquals($paths, $result->paths());
        self::assertSame($paths->briefing(), $result->systemPath());
        self::assertSame($paths->identity(), $result->metaPath());
        self::assertSame($paths->bundle(), $result->bundlePath());
        self::assertSame($paths->identity(), (new CompiledRecallOutputReader())->identityPath('/recall/ABC-1'));
    }
}
