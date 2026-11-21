<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler;

use voku\AgentRecallCompiler\Output\CompiledRecallOutputPaths;

/**
 * Stable owner-level result of a successful Recall compilation.
 */
final readonly class CompileResult
{
    public function __construct(
        public string $outputDirectory,
        public string $compilationId,
        public string $bundleSha256,
    ) {
    }

    public function systemPath(): string
    {
        return $this->paths()->briefing();
    }

    public function validationPlanPath(): string
    {
        return $this->paths()->validationPlan();
    }

    public function factsPath(): string
    {
        return $this->paths()->facts();
    }

    public function metaPath(): string
    {
        return $this->paths()->identity();
    }

    public function bundlePath(): string
    {
        return $this->paths()->bundle();
    }

    /** Every artifact path of this compilation, owned by Recall. */
    public function paths(): CompiledRecallOutputPaths
    {
        return new CompiledRecallOutputPaths($this->outputDirectory);
    }
}
