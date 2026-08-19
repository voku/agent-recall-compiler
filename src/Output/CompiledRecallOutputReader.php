<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Output;

use JsonException;
use RuntimeException;

/**
 * Reads a compiled Recall output directory without creating or mutating it.
 *
 * Recall already owns writing these artifacts. Hosts should not have to know
 * artifact filenames or JSON key names to ask ordinary lifecycle questions.
 */
final readonly class CompiledRecallOutputReader
{
    /** Where the document identifying this output lives, whether or not it exists. */
    public function identityPath(string $outputDirectory): string
    {
        return rtrim($outputDirectory, '/\\') . '/meta.json';
    }

    public function read(string $outputDirectory): ?CompiledRecallOutput
    {
        $directory = rtrim($outputDirectory, '/\\');
        $metaPath = $this->identityPath($directory);
        if (!is_file($metaPath)) {
            return null;
        }

        $meta = $this->decode($metaPath);
        $bundlePath = $directory . '/recall.bundle.json';
        $bundlePresent = is_file($bundlePath);

        $boundTaskId = null;
        $boundRevision = null;
        $bundleReadable = true;
        if ($bundlePresent) {
            // A corrupt bundle is distinct from a missing or mismatched one, so
            // report it instead of throwing: the host can route to recompilation.
            try {
                $bundle = $this->decode($bundlePath);
                $task = $bundle['task'] ?? null;
                if (is_array($task)) {
                    $boundTaskId = is_string($task['id'] ?? null) ? $task['id'] : null;
                    $boundRevision = is_int($task['revision'] ?? null) ? $task['revision'] : null;
                }
            } catch (RuntimeException) {
                $bundleReadable = false;
            }
        }

        [$factsPresent, $factsReadable, $facts] = $this->facts($directory);

        return new CompiledRecallOutput(
            identityPath: $metaPath,
            describedTaskId: $this->stringOrNull($meta['task_id'] ?? null),
            compilationId: $this->stringOrNull($meta['compilation_id'] ?? null),
            bundleSha256: $this->stringOrNull($meta['bundle_sha256'] ?? null),
            snapshotSha256: $this->stringOrNull($meta['snapshot_sha256'] ?? null),
            blocked: ($meta['blocked'] ?? false) === true,
            blockReason: $this->stringOrNull($meta['block_reason'] ?? null),
            boundTaskId: $boundTaskId,
            boundContractRevision: $boundRevision,
            bundlePresent: $bundlePresent,
            bundleReadable: $bundleReadable,
            factsPresent: $factsPresent,
            factsReadable: $factsReadable,
            outcomeDraftPresent: is_file($directory . '/recall-log.draft.json'),
            taskFiles: $this->stringList($meta['task_files'] ?? null),
            selectedGuidance: $this->stringList($meta['selected_guidance'] ?? null),
            selectedConstraints: $this->constraintIds($meta['selected_constraints'] ?? null),
            facts: $facts,
        );
    }

    /** @return array{bool, bool, list<RecallFact>} */
    private function facts(string $directory): array
    {
        $path = $directory . '/facts.json';
        if (!is_file($path)) {
            return [false, true, []];
        }

        try {
            $document = $this->decode($path);
            $rows = $document['facts'] ?? null;
            if (!is_array($rows)) {
                throw new RuntimeException('Recall facts document requires a facts list: ' . $path);
            }
        } catch (RuntimeException) {
            return [true, false, []];
        }

        $facts = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row['type'] ?? null)) {
                continue;
            }
            /** @var array<string, mixed> $payload */
            $payload = is_array($row['payload'] ?? null) ? $row['payload'] : [];
            $facts[] = new RecallFact(
                type: $row['type'],
                payload: $payload,
                sourceRef: $this->stringOrNull($row['source_ref'] ?? null),
                scope: $this->stringList($row['scope'] ?? null),
            );
        }

        return [true, true, $facts];
    }

    /** @return array<string, mixed> */
    private function decode(string $path): array
    {
        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new RuntimeException('Unable to read Recall output document: ' . $path);
        }

        try {
            $root = json_decode($contents, false, 512, JSON_THROW_ON_ERROR);
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Invalid Recall output JSON ' . $path . ': ' . $exception->getMessage(), 0, $exception);
        }
        if (!is_object($root) || !is_array($decoded)) {
            throw new RuntimeException('Recall output JSON must decode to an object: ' . $path);
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $items[] = $item;
            }
        }

        return $items;
    }

    /** @return list<string> */
    private function constraintIds(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $ids = [];
        foreach ($value as $constraint) {
            if (is_string($constraint) && $constraint !== '') {
                // Keep backward compatibility with early/hand-written fixtures.
                $ids[] = $constraint;
                continue;
            }
            if (is_array($constraint) && is_string($constraint['id'] ?? null) && $constraint['id'] !== '') {
                $ids[] = $constraint['id'];
            }
        }

        return $ids;
    }
}
