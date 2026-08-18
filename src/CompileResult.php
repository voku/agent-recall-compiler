<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler;

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
        return $this->artifactPath('system.md');
    }

    public function validationPlanPath(): string
    {
        return $this->artifactPath('validation-plan.md');
    }

    public function factsPath(): string
    {
        return $this->artifactPath('facts.json');
    }

    public function metaPath(): string
    {
        return $this->artifactPath('meta.json');
    }

    public function bundlePath(): string
    {
        return $this->artifactPath('recall.bundle.json');
    }

    private function artifactPath(string $filename): string
    {
        return rtrim($this->outputDirectory, '/\\') . '/' . $filename;
    }
}
