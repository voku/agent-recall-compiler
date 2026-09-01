<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentRecallCompiler\Compilation\FactResolver;
use voku\AgentRecallCompiler\Provider\RecallFact;

final class LearningPrecedentAuthorityTest extends TestCase
{
    public function testPrecedentWinsLegacyRepositoryMemoryButNotProjectSkill(): void
    {
        $repositoryMemory = new RecallFact(
            id: 'memory.legacy',
            type: 'memory',
            authority: 'repository_memory',
            sourceRef: 'MEMORY.md',
            scope: ['src/'],
            payload: ['text' => 'Legacy memory'],
            conflictKey: 'ownership.rule',
        );
        $precedent = new RecallFact(
            id: 'learning-note.precedent',
            type: 'learning_precedent',
            authority: 'learning_precedent',
            sourceRef: 'agent-learning:learning-note.precedent',
            scope: ['src/'],
            payload: ['text' => 'Validated solved case'],
            conflictKey: 'ownership.rule',
        );
        $projectSkill = new RecallFact(
            id: 'skill.current',
            type: 'skill',
            authority: 'project_skill',
            sourceRef: 'skills/current/SKILL.md',
            scope: ['src/'],
            payload: ['text' => 'Current reviewed skill'],
            conflictKey: 'ownership.rule',
        );

        $withoutSkill = (new FactResolver())->resolve([$repositoryMemory, $precedent]);
        self::assertSame(['learning-note.precedent'], array_column($withoutSkill->facts, 'id'));

        $withSkill = (new FactResolver())->resolve([$repositoryMemory, $precedent, $projectSkill]);
        self::assertSame(['skill.current'], array_column($withSkill->facts, 'id'));
    }
}
