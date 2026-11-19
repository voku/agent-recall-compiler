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

    public function testConsumerSkillLinksStayInsideTheProjectedSkill(): void
    {
        // Consumer skills are copied into other repositories (for example
        // `.claude/skills/<id>/`). A relative link that leaves the skill
        // directory then resolves against the consumer's tree, not this package.
        foreach (PackageResources::consumerSkills() as $id => $path) {
            foreach (glob($path . '/*.md') ?: [] as $file) {
                $contents = (string) file_get_contents($file);
                self::assertSame(
                    0,
                    preg_match('/\]\(\.\.\//', $contents),
                    sprintf('%s/%s links outside its skill directory; use an absolute package URL.', $id, basename($file)),
                );
            }
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
