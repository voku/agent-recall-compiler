<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler;

final readonly class RecallRootResolver
{
    public function __construct(private PathResolver $pathResolver = new PathResolver())
    {
    }

    public function resolve(?string $explicitRoot): RecallRootConfig
    {
        $root = $this->pathResolver->resolve($explicitRoot);
        $activeConstraintsDir = 'constraints/active';
        $configPath = $root . '/config.json';
        if (is_file($configPath)) {
            $content = file_get_contents($configPath);
            if ($content !== false) {
                $data = json_decode($content, true);
                if (is_array($data) && is_string($data['active_constraints_dir'] ?? null) && trim($data['active_constraints_dir']) !== '') {
                    $activeConstraintsDir = trim($data['active_constraints_dir']);
                }
            }
        }

        return new RecallRootConfig($root, $activeConstraintsDir);
    }
}
