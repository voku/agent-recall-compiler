<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Output;

use RuntimeException;

/**
 * Archives one compiled Recall output directory before it is replaced.
 *
 * Compiled Recall output is derived state, but a failed replacement compile must
 * not leave an older meta/review set at the canonical task path. Archiving the
 * prior directory preserves audit evidence while making the canonical path empty
 * for the next compile.
 */
final readonly class CompiledRecallOutputSuperseder
{
    public function __construct(private CompiledRecallOutputReader $reader = new CompiledRecallOutputReader())
    {
    }

    /**
     * @return non-empty-string|null absolute/archive path, or null when no output exists
     */
    public function archiveIfPresent(string $directory): ?string
    {
        $directory = rtrim($directory, '/\\');
        if ($directory === '' || !is_dir($directory)) {
            return null;
        }

        $identitySource = $this->reader->identityPath($directory);
        $digest = is_file($identitySource) ? hash_file('sha256', $identitySource) : false;
        $suffix = $digest === false ? 'unknown' : substr($digest, 0, 12);
        $archive = $directory . '.superseded-' . $suffix;

        for ($attempt = 1; file_exists($archive); ++$attempt) {
            $archive = $directory . '.superseded-' . $suffix . '-' . $attempt;
        }

        if (!rename($directory, $archive)) {
            throw new RuntimeException('Unable to archive superseded recall output: ' . $directory);
        }

        return $archive;
    }
}
