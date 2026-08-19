<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Output;

/**
 * Answers a lifecycle host asks about one compiled Recall output directory.
 *
 * This deliberately exposes semantic questions rather than the documents behind
 * them. A host that has to read `meta.json` keys or know that a bundle lives in
 * `recall.bundle.json` has taken on Recall's serialization as a private
 * dependency, which is the coupling this type exists to remove.
 */
final readonly class CompiledRecallOutput
{
    /**
     * @param list<string> $selectedGuidance
     * @param list<string> $selectedConstraints
     * @param list<RecallFact> $facts
     */
    public function __construct(
        private ?string $compilationId,
        private ?string $bundleSha256,
        private ?string $snapshotSha256,
        private bool $blocked,
        private ?string $blockReason,
        private ?string $describedTaskId,
        private ?string $boundTaskId,
        private ?int $boundContractRevision,
        private bool $bundlePresent,
        private array $selectedGuidance,
        private array $selectedConstraints,
        private array $facts,
    ) {
    }

    /**
     * Whether the compilation metadata claims this task at all.
     *
     * This is a weaker statement than bindsTo(): metadata can name the right
     * task while the compiled bundle is bound to an older revision.
     */
    public function describesTask(string $taskId): bool
    {
        return $this->describedTaskId === null || $this->describedTaskId === $taskId;
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

    /**
     * Whether this output was compiled for exactly this task and Contract revision.
     *
     * A false answer means the directory describes previous work: the caller is
     * looking at superseded output, not at evidence for the current Run.
     */
    public function bindsTo(string $taskId, int $contractRevision): bool
    {
        return $this->boundTaskId === $taskId && $this->boundContractRevision === $contractRevision;
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
}
