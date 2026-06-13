<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler;

use InvalidArgumentException;

final class PathResolver
{
    /**
     * Resolve a path. If null, auto-discovers by searching up from CWD.
     */
    public function resolve(?string $path = null): string
    {
        if ($path !== null && trim($path) !== '') {
            $real = realpath($path);
            if ($real === false) {
                // Check if directory can be created or if it just doesn't exist
                return rtrim(str_replace('\\', '/', $path), '/');
            }
            return str_replace('\\', '/', $real);
        }

        // Auto-discovery: search upwards from current working directory
        $cwd = getcwd();
        if ($cwd === false) {
            throw new InvalidArgumentException('cannot resolve current working directory');
        }

        $dir = str_replace('\\', '/', $cwd);
        while (true) {
            if (is_dir($dir . '/infra/doc/agent-learning')) {
                return $dir . '/infra/doc/agent-learning';
            }
            if (is_dir($dir . '/findings') && is_dir($dir . '/proposals')) {
                return $dir;
            }

            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = str_replace('\\', '/', $parent);
        }

        return str_replace('\\', '/', $cwd);
    }
}
