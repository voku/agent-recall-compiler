<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler;

final class RedactionGuard
{
    private const string SECRET_ASSIGNMENT_PATTERN = '/(?<![a-z0-9_])(?:password|token|api[_-]?key|ms-Mcs-AdmPwd)\s*[=:]\s*\S+/i';

    /**
     * @param array<string, mixed>|string $value
     */
    public function assertSafe(array|string $value, string $file, ?int $line = null, ?string $eventId = null): void
    {
        $text = is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR) : $value;
        if (preg_match(self::SECRET_ASSIGNMENT_PATTERN, $text) === 1) {
            throw new \RuntimeException(sprintf(
                'sensitive-data match in %s%s%s',
                $file,
                $line === null ? '' : ':' . $line,
                $eventId === null ? '' : ' event ' . $eventId,
            ));
        }
    }
}
