<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler;

use InvalidArgumentException;

final readonly class OperatingPromptRequest
{
    /** @var array<string, bool|int|string> */
    public array $arguments;

    /**
     * @param array<array-key, mixed> $arguments
     */
    public function __construct(
        public string $id,
        array $arguments = [],
    ) {
        if (preg_match('/\A[a-z][a-z0-9._-]*\z/', $id) !== 1) {
            throw new InvalidArgumentException('operating prompt id must match [a-z][a-z0-9._-]*: ' . $id);
        }

        $normalized = [];
        foreach ($arguments as $name => $value) {
            if (!is_string($name) || preg_match('/\A[a-z][a-z0-9_]*\z/', $name) !== 1) {
                throw new InvalidArgumentException('operating prompt argument name must match [a-z][a-z0-9_]*: ' . $name);
            }
            if (!is_bool($value) && !is_int($value) && !is_string($value)) {
                throw new InvalidArgumentException('operating prompt arguments must be boolean, integer, or string values: ' . $name);
            }
            if (is_string($value) && trim($value) === '') {
                throw new InvalidArgumentException('operating prompt argument must not be an empty string: ' . $name);
            }
            $normalized[$name] = $value;
        }
        $this->arguments = $normalized;
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
        if (!is_array($arguments)) {
            throw new InvalidArgumentException('operating prompt arguments must be a JSON object');
        }

        /** @var array<array-key, mixed> $arguments */
        return new self(trim($id), $arguments);
    }

    /** @return array{id: string, arguments: array<string, bool|int|string>} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'arguments' => $this->arguments,
        ];
    }
}
