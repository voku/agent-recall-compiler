<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler;

use RuntimeException;

/**
 * Owner-controlled access to the operating-prompt manifest bundled with the
 * installed Recall package.
 *
 * Consumers must not derive package paths from reflected source locations or
 * know the internal `skills/agent-recall-consumer` layout.
 */
final readonly class BundledOperatingPromptManifest
{
    public static function consumer(): string
    {
        $path = dirname(__DIR__) . '/skills/agent-recall-consumer/operating-prompts.json';
        if (!is_file($path)) {
            throw new RuntimeException('Bundled agent-recall-consumer operating prompt manifest is missing.');
        }

        return $path;
    }
}
