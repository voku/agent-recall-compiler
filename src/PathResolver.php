<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler;

use InvalidArgumentException;

final class PathResolver
{
    /**
     * @var list<string>
     */
    private const array LEARNING_ROOT_CANDIDATES = [
        '.agent-loop/learning',
        'infra/doc/agent-learning',
        '.agent-learning',
        'docs/agent-learning',
        'agent-learning',
    ];

    /**
     * Resolve a path. If null, auto-discovers by searching up from CWD.
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
            foreach (self::LEARNING_ROOT_CANDIDATES as $candidate) {
                if (is_dir($dir . '/' . $candidate)) {
                    return $dir . '/' . $candidate;
                }
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

        return str_replace('\\', '/', $cwd . '/.agent-loop/learning');
    }
}
