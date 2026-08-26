<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler;

use InvalidArgumentException;

final readonly class OperatingPromptRecipe
{
    public const string PURPOSE_EXECUTE = 'execute';
    public const string PURPOSE_HANDOFF = 'handoff';
    public const string PURPOSE_PLAN = 'plan';
    public const string PURPOSE_RECOVER = 'recover';
    public const string PURPOSE_REPORT = 'report';
    public const string PURPOSE_REVIEW = 'review';
    public const string PURPOSE_SIMPLIFY = 'simplify';
    public const string PURPOSE_START = 'start';
    public const string PURPOSE_UNSPECIFIED = 'unspecified';

    /**
     * @param 1|2 $level
     * @param self::PURPOSE_* $purpose
     * @param list<OperatingPromptArgument> $arguments
     */
    public function __construct(
        public string $id,
        public string $title,
        public string $description,
        public int $level,
        public string $purpose,
        public array $arguments,
        public string $sourceRef,
        public string $templateSha256,
    ) {
        if (preg_match('/\A[a-z][a-z0-9._-]*\z/', $id) !== 1) {
            throw new InvalidArgumentException('operating prompt id must match [a-z][a-z0-9._-]*: ' . $id);
        }
        if (trim($title) === '' || trim($description) === '') {
            throw new InvalidArgumentException('operating prompt title and description must not be empty: ' . $id);
        }
        if ($level !== 1 && $level !== 2) {
            throw new InvalidArgumentException('operating prompt level must be 1 or 2: ' . $id);
        }
        if (!in_array($purpose, [
            self::PURPOSE_EXECUTE,
            self::PURPOSE_HANDOFF,
            self::PURPOSE_PLAN,
            self::PURPOSE_RECOVER,
            self::PURPOSE_REPORT,
            self::PURPOSE_REVIEW,
            self::PURPOSE_SIMPLIFY,
            self::PURPOSE_START,
            self::PURPOSE_UNSPECIFIED,
        ], true)) {
            throw new InvalidArgumentException('unsupported operating prompt purpose: ' . $purpose);
        }
        if (preg_match('/\A[a-f0-9]{64}\z/', $templateSha256) !== 1) {
            throw new InvalidArgumentException('operating prompt template digest must be sha256: ' . $id);
        }
    }
}
