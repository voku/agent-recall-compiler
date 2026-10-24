<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Review;

use RuntimeException;

/**
 * Writes review artifacts without following symlinks planted in the output tree.
 */
final class ReviewFileWriter
{
    public static function write(string $workspacePath, string $path, string $contents): void
    {
        $root = realpath($workspacePath);
        $directory = realpath(dirname($path));
        if ($root === false || $directory === false
            || ($directory !== $root && !str_starts_with($directory, rtrim($root, '/\\') . DIRECTORY_SEPARATOR))
        ) {
            throw new RuntimeException('Review output escapes the workspace: ' . dirname($path));
        }
        if (is_link($path)) {
            throw new RuntimeException('Refusing to write through a symlink: ' . $path);
        }

        $temporary = $directory . '/' . basename($path) . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($temporary, $contents, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write review file: ' . $path);
        }
        if (!rename($temporary, $directory . '/' . basename($path))) {
            @unlink($temporary);

            throw new RuntimeException('Unable to replace review file: ' . $path);
        }
    }
}
