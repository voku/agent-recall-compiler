<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Verification;

use JsonException;
use RuntimeException;
use stdClass;
use voku\AgentRecallCompiler\Output\CompiledRecallOutputPaths;

/**
 * Reads the public verification plan of one compiled Recall output.
 *
 * Absence is a legitimate state (a compilation that resolved no map target
 * emits no plan) and returns null, but only once absence is established: the
 * output directory exists and is searchable, and nothing (not even a dangling
 * link) sits at the plan's path. Everything else fails closed and throws:
 * a plan that cannot be reached or read, is not JSON, or does not have the
 * shape the writer emits (schema 1.0, non-empty task_id and target, and
 * knowledge_probes / checklist as lists of entries with unique non-empty ids).
 * Treating any of those as absent or empty would silently drop probe and
 * checklist answer slots.
 */
final readonly class VerificationPlanReader
{
    private const string SCHEMA_VERSION = '1.0';

    public function read(string $outputDirectory): ?VerificationPlanDocument
    {
        $paths = new CompiledRecallOutputPaths($outputDirectory);
        $path = $paths->verificationPlan();
        if (!file_exists($path)) {
            self::assertAbsenceIsEstablished($paths->directory(), $path);

            return null;
        }
        if (!is_file($path)) {
            throw new RuntimeException('Verification plan is not a regular file: ' . $path);
        }
        if (!is_readable($path)) {
            throw new RuntimeException('Unable to read verification plan: ' . $path);
        }

        $raw = file_get_contents($path);
        if (!is_string($raw)) {
            throw new RuntimeException('Unable to read verification plan: ' . $path);
        }

        try {
            $root = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
            $plan = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Invalid verification plan JSON ' . $path . ': ' . $exception->getMessage(), 0, $exception);
        }
        if (!$root instanceof stdClass || !is_array($plan)) {
            throw new RuntimeException('Verification plan JSON must decode to an object: ' . $path);
        }

        $schemaVersion = $root->schema_version ?? null;
        if ($schemaVersion !== self::SCHEMA_VERSION) {
            throw new RuntimeException(sprintf(
                'Unsupported verification plan schema_version %s (expected "%s"): %s',
                json_encode($schemaVersion),
                self::SCHEMA_VERSION,
                $path,
            ));
        }

        /** @var array<string, mixed> $plan */
        return new VerificationPlanDocument(
            path: $path,
            sha256: 'sha256:' . hash('sha256', $raw),
            taskId: self::requiredString($root, 'task_id', $path),
            target: self::requiredString($root, 'target', $path),
            probeIds: self::ids($root, 'knowledge_probes', $path),
            checklistIds: self::ids($root, 'checklist', $path),
            plan: $plan,
        );
    }

    /**
     * file_exists() is false both for a missing plan and for one it cannot
     * reach (an unsearchable or missing directory, a dangling link). Only the
     * first is absence.
     */
    private static function assertAbsenceIsEstablished(string $directory, string $path): void
    {
        if (is_link($path)) {
            throw new RuntimeException('Verification plan is a dangling symbolic link: ' . $path);
        }
        if (!is_dir($directory)) {
            throw new RuntimeException('Cannot establish verification plan absence: output directory does not exist or cannot be reached: ' . $directory);
        }
        if (!is_executable($directory)) {
            throw new RuntimeException('Cannot establish verification plan absence: output directory is not searchable: ' . $directory);
        }
    }

    /** @return non-empty-string */
    private static function requiredString(stdClass $root, string $field, string $path): string
    {
        $value = $root->{$field} ?? null;
        if (!is_string($value) || $value === '') {
            throw new RuntimeException(sprintf('Verification plan %s must be a non-empty string: %s', $field, $path));
        }

        return $value;
    }

    /** @return list<non-empty-string> */
    private static function ids(stdClass $root, string $field, string $path): array
    {
        $entries = $root->{$field} ?? null;
        if (!is_array($entries)) {
            throw new RuntimeException(sprintf('Verification plan %s must be a JSON array: %s', $field, $path));
        }

        $ids = [];
        foreach ($entries as $index => $entry) {
            $id = $entry instanceof stdClass ? ($entry->id ?? null) : null;
            if (!is_string($id) || $id === '') {
                throw new RuntimeException(sprintf('Verification plan %s[%d] must be an object with a non-empty string id: %s', $field, $index, $path));
            }
            if (in_array($id, $ids, true)) {
                throw new RuntimeException(sprintf('Verification plan %s has duplicate id "%s": %s', $field, $id, $path));
            }
            $ids[] = $id;
        }

        return $ids;
    }
}
