<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use voku\AgentRecallCompiler\Command\OptionParser;

/** @internal */
final class CommandTest extends TestCase
{
    public function testOptionParserRejectsBareLongOptions(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Option --task requires a value.');

        (new OptionParser())->parse(['--task']);
    }

    public function testOptionParserParsesRepeatedValuedOptions(): void
    {
        $parsed = (new OptionParser())->parse(['--task', 'ABC-123', '--file', 'src/Foo.php', '--file', 'tests/FooTest.php']);

        self::assertSame('ABC-123', $parsed->stringOption('task'));
        self::assertSame(['src/Foo.php', 'tests/FooTest.php'], $parsed->stringOptions('file'));
    }
}
