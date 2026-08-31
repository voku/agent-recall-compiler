<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler;

use RuntimeException;

/**
 * Stable owner boundary for the first-party Recall skills shipped by this package.
 *
 * Hosts may project these skills without locating the package through CLI
 * reflection or depending on the rest of Recall's internal directory layout.
 */
final class FirstPartySkillCatalog
{
    public static function root(): string
    {
        $root = dirname(__DIR__) . '/skills';
        if (!is_dir($root)) {
            throw new RuntimeException('Installed Recall first-party skill directory is missing: ' . $root);
        }

        return $root;
    }

    /** @return list<string> */
    public static function names(): array
    {
        $root = self::root();
        $names = [];
        foreach (scandir($root) ?: [] as $entry) {
            if (!is_string($entry) || str_starts_with($entry, '.')) {
                continue;
            }
            if (is_file($root . '/' . $entry . '/SKILL.md')) {
                $names[] = $entry;
            }
        }

        sort($names, SORT_STRING);

        return $names;
    }
}
