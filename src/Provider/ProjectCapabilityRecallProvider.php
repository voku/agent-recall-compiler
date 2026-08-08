<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Provider;

use JsonException;
use RuntimeException;
use voku\AgentRecallCompiler\CanonicalJson;
use voku\AgentRecallCompiler\RecallRootConfig;
use voku\AgentRecallCompiler\TaskBrief;

/**
 * Deterministically exposes repository tooling evidence used by L2 prompt construction.
 *
 * This provider does not guess commands from tool names and does not scan source code.
 * It reads a small allow-list of project manifests/configuration plus CI workflow names.
 */
final readonly class ProjectCapabilityRecallProvider implements RecallProvider
{
    private const array CONFIG_FILES = [
        'phpunit.xml',
        'phpunit.xml.dist',
        'codeception.yml',
        'codeception.dist.yml',
        'phpstan.neon',
        'phpstan.neon.dist',
        'infection.json',
        'infection.json.dist',
        '.php-cs-fixer.php',
        '.php-cs-fixer.dist.php',
        'rector.php',
    ];

    public function __construct(private string $projectRoot)
    {
    }

    public function manifest(): RecallProviderManifest
    {
        return new RecallProviderManifest(
            'project-capabilities',
            '1.0',
            $this->sourcePaths(),
            false,
        );
    }

    public function collect(TaskBrief $task, RecallRootConfig $rootConfig): RecallProviderResult
    {
        unset($task, $rootConfig);

        $root = $this->normalizedRoot();
        $composerPath = $root . '/composer.json';
        $composer = is_file($composerPath) ? $this->decodeJson($composerPath) : null;
        $configs = $this->existingConfigFiles($root);
        $workflows = $this->workflowFiles($root);

        $payload = [
            'project_root' => $root,
            'language' => $composer === null ? null : 'php',
            'package_manager' => $composer === null ? null : 'composer',
            'runtime_constraint' => $this->composerPhpConstraint($composer),
            'composer_scripts' => $this->composerScripts($composer),
            'tool_packages' => $this->toolPackages($composer),
            'config_files' => $configs,
            'ci_workflows' => $workflows,
        ];

        $sources = [];
        foreach ($this->sourcePaths() as $relativePath) {
            $absolutePath = $root . '/' . $relativePath;
            $hash = hash_file('sha256', $absolutePath);
            if ($hash === false) {
                throw new RuntimeException('Unable to hash project capability source: ' . $absolutePath);
            }
            $sources[$relativePath] = $hash;
        }
        ksort($sources);

        return new RecallProviderResult(
            CanonicalJson::digest(['sources' => $sources, 'payload' => $payload]),
            [new RecallFact(
                'project.capabilities',
                'project_capabilities',
                'project_metadata',
                $composer === null ? $root : $composerPath,
                ['/'],
                $payload,
                'project.capabilities',
                40,
            )],
        );
    }

    /** @return list<string> */
    private function sourcePaths(): array
    {
        $root = $this->normalizedRoot();
        $paths = [];
        if (is_file($root . '/composer.json')) {
            $paths[] = 'composer.json';
        }
        foreach (self::CONFIG_FILES as $path) {
            if (is_file($root . '/' . $path)) {
                $paths[] = $path;
            }
        }
        foreach ($this->workflowFiles($root) as $path) {
            $paths[] = $path;
        }

        sort($paths);

        return array_values(array_unique($paths));
    }

    private function normalizedRoot(): string
    {
        $root = realpath($this->projectRoot);
        if ($root === false || !is_dir($root)) {
            throw new RuntimeException('Project capability root is not a readable directory: ' . $this->projectRoot);
        }

        return rtrim(str_replace('\\', '/', $root), '/');
    }

    /** @return array<string, mixed> */
    private function decodeJson(string $path): array
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Unable to read project manifest: ' . $path);
        }
        try {
            $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Invalid JSON in project manifest ' . $path . ': ' . $exception->getMessage(), 0, $exception);
        }
        if (!is_array($data)) {
            throw new RuntimeException('Project manifest must be a JSON object: ' . $path);
        }

        return $data;
    }

    /** @param array<string, mixed>|null $composer */
    private function composerPhpConstraint(?array $composer): ?string
    {
        $require = $composer['require'] ?? null;
        if (!is_array($require)) {
            return null;
        }
        $php = $require['php'] ?? null;

        return is_string($php) && trim($php) !== '' ? trim($php) : null;
    }

    /**
     * @param array<string, mixed>|null $composer
     * @return array<string, string|list<string>>
     */
    private function composerScripts(?array $composer): array
    {
        $scripts = $composer['scripts'] ?? null;
        if (!is_array($scripts)) {
            return [];
        }

        $result = [];
        foreach ($scripts as $name => $value) {
            if (!is_string($name) || trim($name) === '') {
                continue;
            }
            if (is_string($value)) {
                $result[$name] = $value;
                continue;
            }
            if (!is_array($value)) {
                continue;
            }
            $commands = [];
            foreach ($value as $command) {
                if (is_string($command)) {
                    $commands[] = $command;
                }
            }
            if ($commands !== []) {
                $result[$name] = $commands;
            }
        }
        ksort($result);

        return $result;
    }

    /**
     * @param array<string, mixed>|null $composer
     * @return array<string, string>
     */
    private function toolPackages(?array $composer): array
    {
        if ($composer === null) {
            return [];
        }
        $packages = [];
        foreach (['require', 'require-dev'] as $section) {
            $values = $composer[$section] ?? null;
            if (!is_array($values)) {
                continue;
            }
            foreach ($values as $name => $constraint) {
                if (!is_string($name) || !is_string($constraint)) {
                    continue;
                }
                if ($this->isKnownToolPackage($name)) {
                    $packages[$name] = $constraint;
                }
            }
        }
        ksort($packages);

        return $packages;
    }

    private function isKnownToolPackage(string $name): bool
    {
        return in_array($name, [
            'phpunit/phpunit',
            'codeception/codeception',
            'phpstan/phpstan',
            'infection/infection',
            'friendsofphp/php-cs-fixer',
            'rector/rector',
        ], true);
    }

    /** @return list<string> */
    private function existingConfigFiles(string $root): array
    {
        $files = [];
        foreach (self::CONFIG_FILES as $path) {
            if (is_file($root . '/' . $path)) {
                $files[] = $path;
            }
        }

        return $files;
    }

    /** @return list<string> */
    private function workflowFiles(string $root): array
    {
        $directory = $root . '/.github/workflows';
        if (!is_dir($directory)) {
            return [];
        }

        $files = [];
        foreach (scandir($directory) ?: [] as $entry) {
            if (!preg_match('/\.ya?ml$/', $entry)) {
                continue;
            }
            if (is_file($directory . '/' . $entry)) {
                $files[] = '.github/workflows/' . $entry;
            }
        }
        sort($files);

        return $files;
    }
}
