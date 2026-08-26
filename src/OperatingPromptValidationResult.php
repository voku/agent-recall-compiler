<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler;

final readonly class OperatingPromptValidationResult
{
    /** @param list<string> $errors */
    public function __construct(
        public bool $valid,
        public array $errors,
    ) {
    }

    public static function valid(): self
    {
        return new self(true, []);
    }

    /** @param list<string> $errors */
    public static function invalid(array $errors): self
    {
        return new self(false, $errors);
    }
}
