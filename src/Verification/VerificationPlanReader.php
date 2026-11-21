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
 * output directory can be listed and holds no `verification-plan.json` entry.
 * Everything else fails closed and throws, because treating it as absent or
 * empty would silently drop verification obligations:
 *
 * - an entry that exists but cannot be reached (a dangling or looping link,
 *   open_basedir, a path too long to stat, a denied lookup);
 * - an output directory that is missing or cannot be listed;
 * - a plan that cannot be read, is not JSON, or is not a JSON object;
 * - a plan without the top-level shape the writer emits: `schema_version`
 *   `1.0`, non-empty `task_id`, `target` and `map_digest`, `analysis_fingerprint`
 *   an object or null, `required_evidence_types` a non-empty array of
 *   non-empty strings, `generator` an object with string `name`, `version`
 *   and `seed_sha256`, `omitted_probe_candidates` an array of objects, and
 *   `knowledge_probes`, `checklist` and `objective_gates` arrays of objects
 *   with unique non-empty `id`s.
 *
 * Fields inside those entries other than `id` are not validated here.
 * Unknown top-level keys are tolerated; `schema_version` gates incompatible
 * changes.
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
            throw new RuntimeException('Verification plan is not readable: ' . $path);
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
        self::requiredString($root, 'map_digest', $path);
        if (!property_exists($root, 'analysis_fingerprint') || ($root->analysis_fingerprint !== null && !$root->analysis_fingerprint instanceof stdClass)) {
            throw new RuntimeException('Verification plan analysis_fingerprint must be an object or null: ' . $path);
        }
        self::assertEvidenceTypes($root, $path);
        self::assertGenerator($root, $path);
        self::objects($root, 'omitted_probe_candidates', $path);
        self::ids($root, 'objective_gates', $path);

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
     * reach. Only a directory listing without the plan's entry is absence.
     */
    private static function assertAbsenceIsEstablished(string $directory, string $path): void
    {
        if (!is_dir($directory)) {
            throw new RuntimeException('Cannot establish verification plan absence: output directory does not exist or cannot be reached: ' . $directory);
        }
        $entries = @scandir($directory);
        if ($entries === false) {
            throw new RuntimeException('Cannot establish verification plan absence: output directory cannot be listed: ' . $directory);
        }
        if (in_array(basename($path), $entries, true)) {
            throw new RuntimeException(is_link($path)
                ? 'Verification plan is a symbolic link whose target cannot be reached: ' . $path
                : 'Verification plan exists but cannot be reached: ' . $path);
        }
    }

    private static function assertEvidenceTypes(stdClass $root, string $path): void
    {
        $types = $root->required_evidence_types ?? null;
        if (!is_array($types) || $types === []) {
            throw new RuntimeException('Verification plan required_evidence_types must be a non-empty JSON array: ' . $path);
        }
        foreach ($types as $type) {
            if (!is_string($type) || $type === '') {
                throw new RuntimeException('Verification plan required_evidence_types must contain only non-empty strings: ' . $path);
            }
        }
    }

    private static function assertGenerator(stdClass $root, string $path): void
    {
        $generator = $root->generator ?? null;
        if (!$generator instanceof stdClass) {
            throw new RuntimeException('Verification plan generator must be an object: ' . $path);
        }
        foreach (['name', 'version', 'seed_sha256'] as $field) {
            if (!is_string($generator->{$field} ?? null)) {
                throw new RuntimeException(sprintf('Verification plan generator.%s must be a string: %s', $field, $path));
            }
        }
    }

    /** @return list<stdClass> */
    private static function objects(stdClass $root, string $field, string $path): array
    {
        $entries = $root->{$field} ?? null;
        if (!is_array($entries)) {
            throw new RuntimeException(sprintf('Verification plan %s must be a JSON array: %s', $field, $path));
        }
        foreach ($entries as $index => $entry) {
            if (!$entry instanceof stdClass) {
                throw new RuntimeException(sprintf('Verification plan %s[%d] must be an object: %s', $field, $index, $path));
            }
        }

        /** @var list<stdClass> $entries */
        return $entries;
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
        $ids = [];
        foreach (self::objects($root, $field, $path) as $index => $entry) {
            $id = $entry->id ?? null;
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
