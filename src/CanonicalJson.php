<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler;

/** Stable JSON representation used for replayable recall artifacts. */
final class CanonicalJson
{
    /** @param array<string, mixed>|list<mixed> $value */
    public static function encode(array $value): string
    {
        return json_encode(self::normalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed>|list<mixed> $value */
    public static function pretty(array $value): string
    {
        return json_encode(self::normalize($value), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed>|list<mixed> $value */
    public static function digest(array $value): string
    {
        return hash('sha256', self::encode($value));
    }

    public static function normalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::normalize(...), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::normalize($item);
        }

        return $value;
    }
}
