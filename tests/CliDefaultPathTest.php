<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use voku\AgentRecallCompiler\Cli;

final class CliDefaultPathTest extends TestCase
{
    public function testTaskBriefIdOwnsDefaultOutputDirectoryWhenInlineTaskAlsoExists(): void
    {
        $root = sys_get_temp_dir() . '/agent-recall-cli-default-' . bin2hex(random_bytes(6));
        mkdir($root, 0o775, true);
        $brief = $root . '/brief.json';
        file_put_contents($brief, json_encode([
            'schema_version' => '1.0',
            'id' => 'BRIEF-2',
            'description' => 'Use the effective task source.',
            'files' => [],
        ], JSON_THROW_ON_ERROR));

        $previousDirectory = getcwd();
        self::assertNotFalse($previousDirectory);
        self::assertTrue(chdir($root));

        try {
            $method = new ReflectionMethod(Cli::class, 'compileTokensWithDefaultPaths');
            $tokens = $method->invoke(new Cli(), [
                '--task',
                'INLINE-1',
                '--task-brief',
                $brief,
            ]);
        } finally {
            chdir($previousDirectory);
            unlink($brief);
            rmdir($root);
        }

        self::assertIsArray($tokens);
        $outputOption = array_search('--output-dir', $tokens, true);
        self::assertIsInt($outputOption);
        self::assertSame($root . '/.agent-loop/recall/BRIEF-2', $tokens[$outputOption + 1] ?? null);
    }
}
