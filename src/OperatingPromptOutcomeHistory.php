<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler;

use JsonException;
use RuntimeException;

final readonly class OperatingPromptOutcomeHistory
{
    /**
     * @return array{selected_count:int, applied_count:int, helpful_count:int, irrelevant_count:int, harmful_count:int, not_used_count:int, unknown_count:int}
     */
    public function stats(string $root, string $promptId): array
    {
        $stats = [
            'selected_count' => 0,
            'applied_count' => 0,
            'helpful_count' => 0,
            'irrelevant_count' => 0,
            'harmful_count' => 0,
            'not_used_count' => 0,
            'unknown_count' => 0,
        ];
        $path = rtrim($root, '/\\') . '/history/operating-prompt-outcomes.jsonl';
        if (!is_file($path)) {
            return $stats;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new RuntimeException('cannot read operating prompt outcome history: ' . $path);
        }

        foreach ($lines as $index => $line) {
            if (trim($line) === '') {
                continue;
            }
            try {
                $record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException(sprintf('malformed operating prompt outcome history %s:%d: %s', $path, $index + 1, $exception->getMessage()));
            }
            if (!is_array($record) || ($record['schema_version'] ?? null) !== '1.0') {
                throw new RuntimeException(sprintf('invalid operating prompt outcome record in %s:%d', $path, $index + 1));
            }
            if (($record['prompt_id'] ?? null) !== $promptId) {
                continue;
            }

            ++$stats['selected_count'];
            if (($record['applied'] ?? false) === true) {
                ++$stats['applied_count'];
            }
            $outcome = $record['outcome'] ?? null;
            if (!is_string($outcome) || !OutcomeValue::tryFrom($outcome) instanceof OutcomeValue) {
                throw new RuntimeException(sprintf('invalid operating prompt outcome value in %s:%d', $path, $index + 1));
            }
            $key = match (OutcomeValue::from($outcome)) {
                OutcomeValue::HELPFUL => 'helpful_count',
                OutcomeValue::IRRELEVANT => 'irrelevant_count',
                OutcomeValue::HARMFUL => 'harmful_count',
                OutcomeValue::NOT_USED => 'not_used_count',
                OutcomeValue::UNKNOWN => 'unknown_count',
            };
            ++$stats[$key];
        }

        return $stats;
    }
}
