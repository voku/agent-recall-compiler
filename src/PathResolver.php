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
            $target = $real !== false ? str_replace('\\', '/', $real) : rtrim(str_replace('\\', '/', $path), '/');

            if (is_dir($target) && !$this->isLearningRoot($target)) {
                $configured = $this->configuredLearningRootFromInitJson($target);
                if ($configured !== null && is_dir($configured) && $this->isLearningRoot($configured)) {
                    $realConfigured = realpath($configured);

                    return $realConfigured !== false ? str_replace('\\', '/', $realConfigured) : $configured;
                }

                $candidate = $target . '/' . self::DEFAULT_LEARNING_ROOT;
                if (is_dir($candidate) && $this->isLearningRoot($candidate)) {
                    $realCandidate = realpath($candidate);

                    return $realCandidate !== false ? str_replace('\\', '/', $realCandidate) : $candidate;
                }
            }

            return $target;
        }

        $cwd = getcwd();
        if ($cwd === false) {
            throw new InvalidArgumentException('cannot resolve current working directory');
        }

        $dir = str_replace('\\', '/', $cwd);
        while (true) {
            $configured = $this->configuredLearningRootFromInitJson($dir);
            if ($configured !== null && is_dir($configured) && $this->isLearningRoot($configured)) {
                $real = realpath($configured);

                return $real !== false ? str_replace('\\', '/', $real) : $configured;
            }

            $candidate = $dir . '/' . self::DEFAULT_LEARNING_ROOT;
            if (is_dir($candidate) && $this->isLearningRoot($candidate)) {
                $real = realpath($candidate);

                return $real !== false ? str_replace('\\', '/', $real) : $candidate;
            }

            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = str_replace('\\', '/', $parent);
        }

        return str_replace('\\', '/', $cwd . '/' . self::DEFAULT_LEARNING_ROOT);
    }

    private function configuredLearningRootFromInitJson(string $projectDirectory): ?string
    {
        $initJsonPath = $projectDirectory . '/.agent-loop/init.json';
        if (!is_file($initJsonPath)) {
            return null;
        }

        $content = @file_get_contents($initJsonPath);
        if (!is_string($content)) {
            return null;
        }

        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            return null;
        }

        $configured = $decoded['paths']['learning_root'] ?? null;
        if (!is_string($configured) || trim($configured) === '') {
            return null;
        }

        $configured = trim($configured);
        if (str_starts_with($configured, '/') || preg_match('/^[A-Za-z]:[\\\\\\/]/', $configured) === 1) {
            return rtrim(str_replace('\\', '/', $configured), '/');
        }

        return rtrim(str_replace('\\', '/', $projectDirectory . '/' . $configured), '/');
    }

    private function isLearningRoot(string $path): bool
    {
        return is_dir($path . '/findings')
            || is_dir($path . '/proposals')
            || is_dir($path . '/history')
            || is_dir($path . '/templates');
    }
}
