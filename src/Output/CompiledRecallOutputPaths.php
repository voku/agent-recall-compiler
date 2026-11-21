<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Output;

/**
 * Canonical artifact paths inside one compiled Recall output directory.
 *
 * Recall owns these filenames. Hosts that only need to point at, test for, or
 * report an artifact ask this class instead of spelling the filename, so a
 * rename stays an owner change rather than a cross-package hunt.
 *
 * A path is returned whether or not the artifact exists; existence and
 * content questions belong to the readers.
 */
final readonly class CompiledRecallOutputPaths
{
    private string $directory;

    public function __construct(string $outputDirectory)
    {
        $this->directory = rtrim($outputDirectory, '/\\');
    }

    public function directory(): string
    {
        return $this->directory;
    }

    /** The document identifying the compilation (`meta.json`). */
    public function identity(): string
    {
        return $this->artifact('meta.json');
    }

    /** The compiled developer briefing (`system.md`). */
    public function briefing(): string
    {
        return $this->artifact('system.md');
    }

    public function validationPlan(): string
    {
        return $this->artifact('validation-plan.md');
    }

    public function facts(): string
    {
        return $this->artifact('facts.json');
    }

    public function bundle(): string
    {
        return $this->artifact('recall.bundle.json');
    }

    public function selectionReport(): string
    {
        return $this->artifact('selection-report.json');
    }

    /** The reviewable Recall outcome draft (`recall-log.draft.json`). */
    public function outcomeDraft(): string
    {
        return $this->artifact('recall-log.draft.json');
    }

    public function feedbackAssessmentDraft(): string
    {
        return $this->artifact('feedback-assessment.draft.json');
    }

    /** The public verification plan; read it through {@see \voku\AgentRecallCompiler\Verification\VerificationPlanReader}. */
    public function verificationPlan(): string
    {
        return $this->artifact('verification-plan.json');
    }

    /** The verifier-owned answer key; never hand it to the agent being verified. */
    public function verificationKey(): string
    {
        return $this->artifact('verification-key.json');
    }

    private function artifact(string $filename): string
    {
        return $this->directory . '/' . $filename;
    }
}
