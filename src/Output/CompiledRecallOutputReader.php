<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Output;

use JsonException;
use RuntimeException;

/**
 * Reads a compiled Recall output directory without creating or mutating it.
 *
 * Recall already owns writing these artifacts. Until this reader existed, every
 * embedding host had to re-derive the filenames and key names to ask ordinary
 * questions about its own Run, so the format was public by accident rather than
 * by contract. Filenames and keys stop here.
 */
final readonly class CompiledRecallOutputReader
{
    public function read(string $outputDirectory): ?CompiledRecallOutput
    {
        $directory = rtrim($outputDirectory, '/\\');
        $metaPath = $directory . '/meta.json';
        if (!is_file($metaPath)) {
            return null;
        }

        $meta = $this->decode($metaPath);
        $bundlePath = $directory . '/recall.bundle.json';
        $bundlePresent = is_file($bundlePath);

        $boundTaskId = null;
        $boundRevision = null;
        if ($bundlePresent) {
            $bundle = $this->decode($bundlePath);
            $task = $bundle['task'] ?? null;
            if (is_array($task)) {
                $boundTaskId = is_string($task['id'] ?? null) ? $task['id'] : null;
                $boundRevision = is_int($task['revision'] ?? null) ? $task['revision'] : null;
            }
        }

        return new CompiledRecallOutput(
            compilationId: $this->stringOrNull($meta['compilation_id'] ?? null),
            bundleSha256: $this->stringOrNull($meta['bundle_sha256'] ?? null),
            snapshotSha256: $this->stringOrNull($meta['snapshot_sha256'] ?? null),
            blocked: ($meta['blocked'] ?? false) === true,
            blockReason: $this->stringOrNull($meta['block_reason'] ?? null),
            boundTaskId: $boundTaskId,
            boundContractRevision: $boundRevision,
            bundlePresent: $bundlePresent,
            selectedGuidance: $this->stringList($meta['selected_guidance'] ?? null),
            selectedConstraints: $this->stringList($meta['selected_constraints'] ?? null),
            facts: $this->facts($directory),
        );
    }

    /** @return list<RecallFact> */
    private function facts(string $directory): array
    {
        $path = $directory . '/facts.json';
        if (!is_file($path)) {
            return [];
        }

        $document = $this->decode($path);
        $rows = $document['facts'] ?? null;
        if (!is_array($rows)) {
            throw new RuntimeException('Recall facts document requires a facts list: ' . $path);
        }

        $facts = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row['type'] ?? null)) {
                continue;
            }
            /** @var array<string, mixed> $payload */
            $payload = is_array($row['payload'] ?? null) ? $row['payload'] : [];
            $facts[] = new RecallFact($row['type'], $payload);
        }

        return $facts;
    }

    /** @return array<string, mixed> */
    private function decode(string $path): array
    {
        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new RuntimeException('Unable to read Recall output document: ' . $path);
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Invalid Recall output JSON ' . $path . ': ' . $exception->getMessage(), 0, $exception);
        }
        if (!is_array($decoded)) {
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
            if (is_string($item)) {
                $items[] = $item;
            }
        }

        return $items;
    }
}
