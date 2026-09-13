<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentRecallCompiler\PackageResources;

/** @internal */
final class PackageResourcesTest extends TestCase
{
    public function testSkillsRootExists(): void
    {
        self::assertDirectoryExists(PackageResources::skillsRoot());
    }

    public function testConsumerSkillsExistAndHaveSkillMd(): void
    {
        $skills = PackageResources::consumerSkills();
        self::assertNotEmpty($skills);
        self::assertArrayHasKey('agent-recall-consumer', $skills);
        self::assertArrayNotHasKey('agent-recall-compiler-maintainer', $skills);

        foreach ($skills as $id => $path) {
            self::assertDirectoryExists($path, "Skill directory for {$id} must exist.");
            self::assertFileExists($path . '/SKILL.md', "SKILL.md for {$id} must exist.");
        }
    }

    public function testMaintainerSkillsExistAndHaveSkillMd(): void
    {
        $skills = PackageResources::maintainerSkills();
        self::assertArrayHasKey('agent-recall-compiler-maintainer', $skills);
        self::assertArrayNotHasKey('agent-recall-consumer', $skills);

        foreach ($skills as $id => $path) {
            self::assertDirectoryExists($path, "Skill directory for {$id} must exist.");
            self::assertFileExists($path . '/SKILL.md', "SKILL.md for {$id} must exist.");
        }
    }

    public function testConsumerInstructionFragmentReturnsNull(): void
    {
        self::assertNull(PackageResources::consumerInstructionFragment());
    }
}
