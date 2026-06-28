<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Command;

final readonly class ParsedOptions
{
    /**
     * @param array<string, list<string>> $options
     * @param list<string> $arguments
     */
    public function __construct(
        public array $options,
        public array $arguments,
    ) {}

    public function stringOption(string $name): ?string
    {
        return $this->options[$name][0] ?? null;
    }

    /** @return list<string> */
    public function stringOptions(string $name): array
    {
        return $this->options[$name] ?? [];
    }
}
