<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Verification;

use JsonException;
use RuntimeException;
use voku\AgentRecallCompiler\Output\CompiledRecallOutputPaths;

/**
 * Reads the public verification plan of one compiled Recall output.
 *
 * Absence is a legitimate state (a compilation that resolved no map target
 * emits no plan) and returns null. A plan that exists but cannot be read or
 * is not a JSON object is corrupt, not absent, and throws: treating it as
 * absent would silently drop every probe and checklist answer slot.
 */
final readonly class VerificationPlanReader
{
    public function read(string $outputDirectory): ?VerificationPlanDocument
    {
        $path = (new CompiledRecallOutputPaths($outputDirectory))->verificationPlan();
        if (!file_exists($path)) {
            return null;
        }
        if (!is_file($path)) {
            throw new RuntimeException('Verification plan is not a regular file: ' . $path);
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
        if (!is_object($root) || !is_array($plan)) {
            throw new RuntimeException('Verification plan JSON must decode to an object: ' . $path);
        }

        /** @var array<string, mixed> $plan */
        return new VerificationPlanDocument(
            path: $path,
            sha256: 'sha256:' . hash('sha256', $raw),
            taskId: self::nonEmptyString($plan['task_id'] ?? null),
            target: self::nonEmptyString($plan['target'] ?? null),
            probeIds: self::ids($plan['knowledge_probes'] ?? null),
            checklistIds: self::ids($plan['checklist'] ?? null),
            plan: $plan,
        );
    }

    /** @return non-empty-string|null */
    private static function nonEmptyString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return list<non-empty-string> */
    private static function ids(mixed $entries): array
    {
        if (!is_array($entries)) {
            return [];
        }

        $ids = [];
        foreach ($entries as $entry) {
            if (is_array($entry) && is_string($entry['id'] ?? null) && $entry['id'] !== '') {
                $ids[] = $entry['id'];
            }
        }

        return $ids;
    }
}
