<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Provider;

use RuntimeException;
use voku\AgentRecallCompiler\CanonicalJson;
use voku\AgentRecallCompiler\RecallRootConfig;
use voku\AgentRecallCompiler\TaskBrief;

/**
 * Reads the ITP owner's syntax-only JSON. Never runs PHP rule definitions or
 * promotes source candidates into Contract authority.
 */
final readonly class ItpSourceHintsRecallProvider implements RecallProvider
{
    private const int MAX_PROJECTION_BYTES = 131072;
    private const int MAX_RULES = 16;
    private const int MAX_FILES = 8;

    public function __construct(private string $projectionPath)
    {
    }

    public function manifest(): RecallProviderManifest
    {
        return new RecallProviderManifest('itp-source-hints', '1.0', [$this->projectionPath], required: false);
    }

    public function collect(TaskBrief $task, RecallRootConfig $rootConfig): RecallProviderResult
    {
        $contents = @file_get_contents($this->projectionPath, false, null, 0, self::MAX_PROJECTION_BYTES + 1);
        if ($contents === false || strlen($contents) > self::MAX_PROJECTION_BYTES) {
            throw new RuntimeException('Cannot read bounded ITP source-hints projection: ' . $this->projectionPath);
        }
        $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)
            || ($data['owner'] ?? null) !== 'itp-context'
            || ($data['authority'] ?? null) !== 'none'
            || ($data['resolution'] ?? null) !== 'syntax_only'
            || !in_array($data['status'] ?? null, ['ready', 'abstained'], true)
        ) {
            throw new RuntimeException('Invalid ITP source-hints owner projection: ' . $this->projectionPath);
        }

        $digest = CanonicalJson::digest($data);
        if ($data['status'] === 'abstained') {
            $reason = $data['reason'] ?? null;
            if (!is_string($reason) || $reason === '') {
                throw new RuntimeException('ITP abstention requires a reason');
            }

            return new RecallProviderResult($digest, [$this->statusFact('abstained', $reason)]);
        }

        $inspected = $data['inspected_files'] ?? null;
        $rules = $data['rules'] ?? null;
        if (!is_array($inspected) || !array_is_list($inspected) || count($inspected) > self::MAX_FILES
            || !is_array($rules) || !array_is_list($rules) || count($rules) > self::MAX_RULES
        ) {
            throw new RuntimeException('ITP source-hints projection exceeds owner bounds');
        }

        $root = realpath($rootConfig->projectRoot ?? '');
        if ($root === false || !is_dir($root)) {
            return new RecallProviderResult($digest, [$this->statusFact('unavailable', 'project_root_unavailable')]);
        }

        /** @var array<string, true> $verifiedPaths */
        $verifiedPaths = [];
        foreach ($inspected as $entry) {
            if (!is_array($entry) || !is_string($entry['path'] ?? null)
                || !is_string($entry['sha256'] ?? null)
            ) {
                throw new RuntimeException('Invalid ITP inspected source record');
            }
            $path = $entry['path'];
            $sha = $entry['sha256'];
            if (!$this->relativePhpPath($path) || preg_match('/^sha256:[a-f0-9]{64}$/D', $sha) !== 1
                || isset($verifiedPaths[$path])
            ) {
                throw new RuntimeException('Invalid or repeated ITP source identity');
            }

            $real = realpath($root . '/' . $path);
            if ($real === false || !str_starts_with($real, rtrim($root, '/') . '/')
                || !is_file($real) || !is_readable($real)
            ) {
                return new RecallProviderResult($digest, [$this->statusFact('stale', 'source_missing_or_outside_project')]);
            }
            $actual = hash_file('sha256', $real);
            if ($actual === false || 'sha256:' . $actual !== $sha) {
                return new RecallProviderResult($digest, [$this->statusFact('stale', 'source_digest_mismatch')]);
            }
            $verifiedPaths[$path] = true;
        }

        $facts = [];
        foreach ($rules as $index => $rule) {
            if (!is_array($rule) || !is_string($rule['rule_id'] ?? null)
                || ($rule['evidence'] ?? null) !== 'source_attribute_only'
                || !is_array($rule['files'] ?? null) || !array_is_list($rule['files'])
            ) {
                throw new RuntimeException('Invalid ITP architecture-rule record');
            }
            $id = $rule['rule_id'];
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]*::[A-Za-z_][A-Za-z0-9_]*$/D', $id) !== 1) {
                throw new RuntimeException('Invalid ITP architecture-rule identifier');
            }
            $paths = $rule['files'];
            if ($paths === [] || count($paths) > self::MAX_FILES) {
                throw new RuntimeException('Invalid ITP rule file references');
            }
            foreach ($paths as $path) {
                if (!is_string($path) || !isset($verifiedPaths[$path])) {
                    throw new RuntimeException('ITP rule references a source outside the verified snapshot');
                }
            }

            $facts[] = new RecallFact(
                'itp.source-rule.' . $index,
                'architecture_hint',
                'derived_navigation',
                $this->projectionPath,
                $paths,
                ['rule_id' => $id, 'files' => $paths, 'evidence' => 'source_attribute_only'],
            );
        }

        return new RecallProviderResult($digest, $facts);
    }

    private function relativePhpPath(string $path): bool
    {
        return $path !== ''
            && !str_starts_with($path, '/')
            && !str_contains($path, '\\')
            && !str_contains($path, "\0")
            && !str_contains($path, "\n")
            && !str_contains($path, "\r")
            && !str_contains($path, chr(96))
            && !in_array('..', explode('/', $path), true)
            && !in_array('.', explode('/', $path), true)
            && preg_match('/\.php$/iD', $path) === 1;
    }

    private function statusFact(string $status, string $reason): RecallFact
    {
        return new RecallFact(
            'itp.source-hints.status',
            'architecture_status',
            'derived_navigation',
            $this->projectionPath,
            [],
            ['status' => $status, 'reason' => $reason],
        );
    }
}
