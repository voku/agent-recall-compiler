<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Output;

use JsonException;
use RuntimeException;

/** Reads one persisted facts.json without exposing its serialization contract. */
final readonly class RecallFactsDocumentReader
{
    public function read(string $path): ?RecallFactsDocument
    {
        if (!is_file($path)) {
            return null;
        }

        $document = $this->decode($path);
        if (($document['schema_version'] ?? null) !== '1.0') {
            throw new RuntimeException('Unsupported Recall facts schema version: ' . $path);
        }

        $bundleSha256 = $document['bundle_sha256'] ?? null;
        if (!is_string($bundleSha256) || preg_match('/^[a-f0-9]{64}$/D', $bundleSha256) !== 1) {
            throw new RuntimeException('Recall facts document requires a canonical bundle_sha256: ' . $path);
        }

        $rows = $document['facts'] ?? null;
        if (!is_array($rows) || !array_is_list($rows)) {
            throw new RuntimeException('Recall facts document requires a facts list: ' . $path);
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

        return new RecallFactsDocument(
            identityPath: $path,
            bundleSha256: $bundleSha256,
            facts: $facts,
        );
    }

    /** @return array<string, mixed> */
    private function decode(string $path): array
    {
        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new RuntimeException('Unable to read Recall facts document: ' . $path);
        }

        try {
            $root = json_decode($contents, false, 512, JSON_THROW_ON_ERROR);
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Invalid Recall facts JSON ' . $path . ': ' . $exception->getMessage(), 0, $exception);
        }
        if (!is_object($root) || !is_array($decoded)) {
            throw new RuntimeException('Recall facts JSON must decode to an object: ' . $path);
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
}
