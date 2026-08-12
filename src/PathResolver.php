<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler;

use InvalidArgumentException;

final class PathResolver
{
    private const string DEFAULT_LEARNING_ROOT = '.agent-loop/learning';

    /**
     * Resolve a path. If null, auto-discovers the canonical learning root by searching up from CWD.
     */
    public function resolve(?string $path = null): string
    {
        if ($path !== null && trim($path) !== '') {
            $real = realpath($path);
            if ($real === false) {
                return rtrim(str_replace('\\', '/', $path), '/');
            }

            return str_replace('\\', '/', $real);
        }

        $cwd = getcwd();
        if ($cwd === false) {
            throw new InvalidArgumentException('cannot resolve current working directory');
        }

        $dir = str_replace('\\', '/', $cwd);
        while (true) {
            $candidate = $dir . '/' . self::DEFAULT_LEARNING_ROOT;
            if (is_dir($candidate)) {
                return $candidate;
            }

            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = str_replace('\\', '/', $parent);
        }

        return str_replace('\\', '/', $cwd . '/' . self::DEFAULT_LEARNING_ROOT);
    }
}
