<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentRecallCompiler\Output\CompiledRecallOutputReader;

/** @internal */
final class CompiledRecallOutputReaderTaskIdTest extends TestCase
{
    public function testTaskScopedReadsRejectPathTraversal(): void
    {
        $reader = new CompiledRecallOutputReader();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid task id.');

        $reader->briefingForTask(sys_get_temp_dir() . '/recall-root', '../outside');
    }
}
