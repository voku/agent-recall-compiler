<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Output;

/**
 * Answers lifecycle-host questions about one compiled Recall output directory.
 *
 * This deliberately exposes semantic questions rather than the documents behind
 * them. A host that has to read Recall JSON keys or know artifact filenames has
 * taken on Recall's serialization as a private dependency.
 */
final readonly class CompiledRecallOutput
{
    /**
     * @param list<string> $taskFiles
     * @param list<string> $selectedGuidance
     * @param list<string> $selectedConstraints
     * @param list<RecallFact> $facts
     * @param list<string> $integrityFailures
     */
    public function __construct(
        private string $identityPath,
        private ?string $compilationId,
        private ?string $bundleSha256,
        private ?string $snapshotSha256,
        private bool $blocked,
        private ?string $blockReason,
        private ?string $describedTaskId,
        private ?string $boundTaskId,
        private ?int $boundContractRevision,
        private bool $bundlePresent,
        private bool $bundleReadable,
        private bool $factsPresent,
        private bool $factsReadable,
        private bool $outcomeDraftPresent,
        private array $taskFiles,
        private array $selectedGuidance,
        private array $selectedConstraints,
        private array $facts,
        private array $integrityFailures,
    ) {
    }

    /** Path of the document identifying this compiled output. */
    public function identityPath(): string
    {
        return $this->identityPath;
    }

    /**
     * Whether the compilation metadata explicitly claims this task.
     *
     * This is weaker than bindsTo(): metadata can name the right task while the
     * compiled bundle is bound to an older Contract revision.
     */
    public function describesTask(string $taskId): bool
    {
        return $this->describedTaskId === $taskId;
    }

    public function compilationId(): ?string
    {
        return $this->compilationId;
    }

    public function bundleSha256(): ?string
    {
        return $this->bundleSha256;
    }

    public function snapshotSha256(): ?string
    {
        return $this->snapshotSha256;
    }

    public function isBlocked(): bool
    {
        return $this->blocked;
    }

    public function blockReason(): ?string
    {
        return $this->blockReason;
    }

    /** Whether this output was compiled for exactly this task and Contract revision. */
    public function bindsTo(string $taskId, int $contractRevision): bool
    {
        return $this->boundTaskId === $taskId && $this->boundContractRevision === $contractRevision;
    }

    /** Whether the compiled bundle has been written at all. */
    public function hasBundle(): bool
    {
        return $this->bundlePresent;
    }

    /** Whether an existing bundle could be parsed; false means corrupt, not absent. */
    public function isBundleReadable(): bool
    {
        return $this->bundleReadable;
    }

    /** Whether the optional compiled facts document exists. */
    public function hasFacts(): bool
    {
        return $this->factsPresent;
    }

    /** Whether an existing facts document could be parsed; false means corrupt. */
    public function areFactsReadable(): bool
    {
        return $this->factsReadable;
    }

    /** Whether the compiler produced an outcome draft for this compilation. */
    public function hasOutcomeDraft(): bool
    {
        return $this->outcomeDraftPresent;
    }

    /** @return list<string> */
    public function taskFiles(): array
    {
        return $this->taskFiles;
    }

    /**
     * Whether the compilation finished writing the artifacts it promised.
     *
     * A compilation that recorded an implementation snapshot but left no bundle
     * behind is incomplete, not merely unbound.
     */
    public function isComplete(): bool
    {
        return $this->snapshotSha256 === null || $this->bundlePresent;
    }

    /** @return list<string> */
    public function selectedGuidance(): array
    {
        return $this->selectedGuidance;
    }

    /** @return list<string> */
    public function selectedConstraints(): array
    {
        return $this->selectedConstraints;
    }

    /** @return list<RecallFact> */
    public function facts(): array
    {
        return $this->facts;
    }

    /**
     * Output files whose current bytes no longer satisfy Recall's own recorded
     * compilation integrity contract.
     *
     * @return list<string>
     */
    public function integrityFailures(): array
    {
        return $this->integrityFailures;
    }
}
