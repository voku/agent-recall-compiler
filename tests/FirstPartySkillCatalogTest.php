<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentRecallCompiler\FirstPartySkillCatalog;

final class FirstPartySkillCatalogTest extends TestCase
{
    public function testCatalogPublishesInstalledSkillRootAndDeterministicNames(): void
    {
        $root = FirstPartySkillCatalog::root();

        self::assertDirectoryExists($root);
        self::assertSame('skills', basename($root));
        self::assertSame([
            'agent-recall-compiler-maintainer',
            'agent-recall-consumer',
        ], FirstPartySkillCatalog::names());

        foreach (FirstPartySkillCatalog::names() as $name) {
            self::assertFileExists($root . '/' . $name . '/SKILL.md');
        }
    }
}
