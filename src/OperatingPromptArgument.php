<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler;

use InvalidArgumentException;

final readonly class OperatingPromptArgument
{
    public const string TYPE_BOOLEAN = 'boolean';
    public const string TYPE_INTEGER = 'integer';
    public const string TYPE_SCALAR = 'scalar';
    public const string TYPE_STRING = 'string';

    /**
     * @param self::TYPE_* $type
     * @param list<bool|int|string> $examples
     */
    public function __construct(
        public string $name,
        public string $type,
        public bool $required,
        public string $description,
        public ?int $minimum = null,
        public ?int $maximum = null,
        public array $examples = [],
    ) {
        if (preg_match('/\A[a-z][a-z0-9_]*\z/', $name) !== 1) {
            throw new InvalidArgumentException('operating prompt argument name must match [a-z][a-z0-9_]*: ' . $name);
        }
        if (!in_array($type, [self::TYPE_BOOLEAN, self::TYPE_INTEGER, self::TYPE_SCALAR, self::TYPE_STRING], true)) {
            throw new InvalidArgumentException('unsupported operating prompt argument type: ' . $type);
        }
        if (trim($description) === '') {
            throw new InvalidArgumentException('operating prompt argument description must not be empty: ' . $name);
        }
        if (($minimum !== null || $maximum !== null) && $type !== self::TYPE_INTEGER) {
            throw new InvalidArgumentException('numeric bounds require an integer operating prompt argument: ' . $name);
        }
        if ($minimum !== null && $maximum !== null && $minimum > $maximum) {
            throw new InvalidArgumentException('operating prompt argument minimum must not exceed maximum: ' . $name);
        }
    }
}
