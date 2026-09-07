<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentRecallCompiler\OperatingPromptCatalog;
use voku\AgentRecallCompiler\OperatingPromptRequest;

final class PlanHorizonPromptTest extends TestCase
{
    public function testPlanHorizonUsesRollingRealityInsteadOfFixedBacklog(): void
    {
        $preview = OperatingPromptCatalog::bundled()->preview(new OperatingPromptRequest(
            'plan-horizon',
            ['horizon' => 'three independently mergeable slices'],
        ));

        self::assertTrue($preview->validation->valid);
        self::assertNotNull($preview->content);
        self::assertStringContainsString(
            'planning search radius, not a fixed schedule or backlog',
            $preview->content,
        );
        self::assertStringContainsString('current reality overrides stale planning', $preview->content);
        self::assertStringContainsString(
            'current executable frontier as a hypothesis that must be revalidated after every completed slice',
            $preview->content,
        );
        self::assertStringContainsString(
            'If another authorized executable slice remains, voluntarily stopping is invalid.',
            $preview->content,
        );
        self::assertStringContainsString('three independently mergeable slices', $preview->content);
        self::assertStringNotContainsString('produce milestones', $preview->content);
    }
}
