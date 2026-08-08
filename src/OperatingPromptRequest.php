<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler;

use InvalidArgumentException;

final readonly class OperatingPromptRequest
{
    /**
     * @param array<string, bool|float|int|string> $arguments
     */
    public function __construct(
        public string $id,
        public array $arguments = [],
    ) {
        if (preg_match('/\A[a-z][a-z0-9._-]*\z/', $id) !== 1) {
            throw new InvalidArgumentException('operating prompt id must match [a-z][a-z0-9._-]*: ' . $id);
        }

        foreach ($arguments as $name => $value) {
            if (preg_match('/\A[a-z][a-z0-9_]*\z/', $name) !== 1) {
                throw new InvalidArgumentException('operating prompt argument name must match [a-z][a-z0-9_]*: ' . $name);
            }
            if (is_string($value) && trim($value) === '') {
                throw new InvalidArgumentException('operating prompt argument must not be an empty string: ' . $name);
            }
            if (is_float($value) && !is_finite($value)) {
                throw new InvalidArgumentException('operating prompt argument must be finite: ' . $name);
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $id = $data['id'] ?? null;
        if (!is_string($id) || trim($id) === '') {
            throw new InvalidArgumentException('operating prompt requires a non-empty string id');
        }

        $arguments = $data['arguments'] ?? [];
        if (!is_array($arguments) || array_is_list($arguments)) {
            throw new InvalidArgumentException('operating prompt arguments must be a JSON object');
        }

        /** @var array<string, bool|float|int|string> $normalized */
        $normalized = [];
        foreach ($arguments as $name => $value) {
            if (!is_string($name)) {
                throw new InvalidArgumentException('operating prompt argument names must be strings');
            }
            if (!is_bool($value) && !is_float($value) && !is_int($value) && !is_string($value)) {
                throw new InvalidArgumentException('operating prompt arguments must be scalar JSON values: ' . $name);
            }
            $normalized[$name] = $value;
        }

        return new self(trim($id), $normalized);
    }

    /** @return array{id: string, arguments: array<string, bool|float|int|string>} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'arguments' => $this->arguments,
        ];
    }
}
