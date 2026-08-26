<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use voku\AgentRecallCompiler\OperatingPromptRequest;

final class OperatingPromptRequestRuntimeValidationTest extends TestCase
{
    public function testDirectConstructionRejectsUnsupportedRuntimeArgumentValues(): void
    {
        $reflection = new ReflectionClass(OperatingPromptRequest::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('operating prompt arguments must be boolean, integer, or string values: ratio');

        $reflection->newInstanceArgs(['regression-hunt', ['ratio' => 1.5]]);
    }
}
