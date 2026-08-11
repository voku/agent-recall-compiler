<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler;

use RuntimeException;

final readonly class RecallRootResolver
{
    /** @var list<string> */
    private const array LEARNING_ROOT_SUFFIXES = [
        '.agent-loop/learning',
        'infra/doc/agent-learning',
        '.agent-learning',
        'docs/agent-learning',
        'agent-learning',
    ];

    public function __construct(private PathResolver $pathResolver = new PathResolver())
    {
    }

    public function resolve(?string $explicitRoot): RecallRootConfig
    {
        $root = $this->pathResolver->resolve($explicitRoot);
        $activeConstraintsDir = 'constraints/active';
        $projectRoot = $this->defaultProjectRoot($root);
        $configPath = $root . '/config.json';

        if (is_file($configPath)) {
            $content = file_get_contents($configPath);
            if ($content === false) {
                throw new RuntimeException('cannot read recall path configuration: ' . $configPath);
            }

            $data = json_decode($content, true);
            if (!is_array($data)) {
                throw new RuntimeException('recall path configuration must be a JSON object: ' . $configPath);
            }

            if (array_key_exists('active_constraints_dir', $data)) {
                if (!is_string($data['active_constraints_dir']) || trim($data['active_constraints_dir']) === '') {
                    throw new RuntimeException('invalid active_constraints_dir in recall path configuration: ' . $configPath);
                }
                $activeConstraintsDir = trim($data['active_constraints_dir']);
            }

            if (array_key_exists('project_root', $data)) {
                if (!is_string($data['project_root']) || trim($data['project_root']) === '') {
                    throw new RuntimeException('invalid project_root in recall path configuration: ' . $configPath);
                }
                $projectRoot = $this->resolveProjectRoot($root, $data['project_root'], $configPath);
            }
        }

        return new RecallRootConfig($root, $activeConstraintsDir, $projectRoot);
    }

    private function defaultProjectRoot(string $root): string
    {
        $normalized = $this->normalize($root);
        foreach (self::LEARNING_ROOT_SUFFIXES as $suffix) {
            $needle = '/' . $suffix;
            if (str_ends_with($normalized, $needle)) {
                return substr($normalized, 0, -strlen($needle));
            }
        }

        return $normalized;
    }

    private function resolveProjectRoot(string $root, string $configured, string $configPath): string
    {
        $configured = trim($configured);
        $candidate = str_starts_with($configured, '/') || preg_match('/^[A-Za-z]:[\\\\\\/]/', $configured) === 1
            ? $configured
            : rtrim($root, '/\\') . '/' . $configured;
        $realPath = realpath($candidate);
        if ($realPath === false || !is_dir($realPath)) {
            throw new RuntimeException('configured project_root directory does not exist in ' . $configPath . ': ' . $configured);
        }

        $projectRoot = $this->normalize($realPath);
        $normalizedLearningRoot = $this->normalize($root);
        $inferredProjectRoot = $this->defaultProjectRoot($normalizedLearningRoot);

        if ($inferredProjectRoot !== $normalizedLearningRoot) {
            $allowedPrefix = rtrim($inferredProjectRoot, '/') . '/';
            if ($projectRoot !== $inferredProjectRoot && !str_starts_with($projectRoot, $allowedPrefix)) {
                throw new RuntimeException('configured project_root escapes the inferred repository root in ' . $configPath . ': ' . $configured);
            }
        }

        return $projectRoot;
    }

    private function normalize(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }
}
