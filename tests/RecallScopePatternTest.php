<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentRecallCompiler\ConstraintManifest;
use voku\AgentRecallCompiler\RecallDecisionEngine;
use voku\AgentRecallCompiler\RecallGuidance;
use voku\AgentRecallCompiler\TaskBrief;

final class RecallScopePatternTest extends TestCase
{
    public function testAFileNamePatternSelectsAConstraintOnlyForMatchingFilesAnywhereInTheTree(): void
    {
        $constraint = $this->constraint('no-redirect', ['*_UnitCest.php']);

        self::assertSame(['no-redirect'], $this->selectedConstraintIds([$constraint], ['lib/framework/Foo_UnitCest.php']));
        self::assertSame([], $this->selectedConstraintIds([$constraint], ['lib/framework/Foo.php']));
    }

    public function testAPathPatternMatchesTheWholePathAndCrossesDirectories(): void
    {
        $constraint = $this->constraint('lib-php', ['modules/*/lib/*.php']);

        self::assertSame(['lib-php'], $this->selectedConstraintIds([$constraint], ['modules/rums/lib/ReadServer.php']));
        self::assertSame(['lib-php'], $this->selectedConstraintIds([$constraint], ['modules/rums/lib/deep/Reader.php']));
        self::assertSame([], $this->selectedConstraintIds([$constraint], ['modules/rums/ReadServer.php']));
    }

    public function testPatternsCombineWithDirectoryPrefixesInOneScope(): void
    {
        $constraint = $this->constraint('mixed', ['docs/', '*_UnitCest.php']);

        self::assertSame(['mixed'], $this->selectedConstraintIds([$constraint], ['docs/guide.md']));
        self::assertSame(['mixed'], $this->selectedConstraintIds([$constraint], ['lib/X_UnitCest.php']));
        self::assertSame([], $this->selectedConstraintIds([$constraint], ['lib/X.php']));
    }

    public function testDirectoryPrefixAndGlobalScopesBehaveAsBefore(): void
    {
        self::assertSame(['dir'], $this->selectedConstraintIds([$this->constraint('dir', ['lib/'])], ['lib/a/B.php']));
        self::assertSame([], $this->selectedConstraintIds([$this->constraint('dir', ['lib/'])], ['docs/a.md']));
        self::assertSame(['all'], $this->selectedConstraintIds([$this->constraint('all', ['*'])], ['anything/at/all.txt']));
    }

    public function testPlainGuidanceScopesAcceptPatternsToo(): void
    {
        $guidance = new RecallGuidance('g-tpl', 'ADD', 'skill', 'tpl', ['*.tpl'], null, 'Wording', 'Reason', null, [], 'approved');

        $selected = (new RecallDecisionEngine())->decide(new TaskBrief('T-1', '', ['templates/page.tpl']), [$guidance], [], [])->selectedGuidance;
        $skipped = (new RecallDecisionEngine())->decide(new TaskBrief('T-1', '', ['templates/page.php']), [$guidance], [], [])->selectedGuidance;

        self::assertSame(['g-tpl'], array_map(static fn (RecallGuidance $g): string => $g->id, $selected));
        self::assertSame([], $skipped);
    }

    /**
     * @param list<string> $scope
     */
    private function constraint(string $id, array $scope): ConstraintManifest
    {
        return new ConstraintManifest($id, 'phpcs', 'rule.' . $id, $scope, ['vendor/bin/phpcs'], 'proposal.2026-01-01.001', 'active');
    }

    /**
     * @param list<ConstraintManifest> $constraints
     * @param list<string>             $files
     *
     * @return list<string>
     */
    private function selectedConstraintIds(array $constraints, array $files): array
    {
        $result = (new RecallDecisionEngine())->decide(new TaskBrief('T-1', '', $files), [], [], [], $constraints);

        return array_map(static fn (ConstraintManifest $c): string => $c->id, $result->selectedConstraints);
    }
}
