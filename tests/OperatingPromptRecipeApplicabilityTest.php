<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentRecallCompiler\OperatingPromptCatalog;

final class OperatingPromptRecipeApplicabilityTest extends TestCase
{
    public function testExecuteRecipesRequireCurrentTaskMutationAuthority(): void
    {
        $recipe = OperatingPromptCatalog::bundled()->recipe('execute-plan-with-blind-spot-check');

        self::assertTrue($recipe->requiresTaskContext());
        self::assertTrue($recipe->requiresMutationAuthority());
        self::assertFalse($recipe->allowsAdditionalInstruction());
    }

    public function testExecutionDispatchRequiresCurrentTaskMutationAuthority(): void
    {
        $recipe = OperatingPromptCatalog::bundled()->recipe('execution-dispatch');

        self::assertTrue($recipe->requiresTaskContext());
        self::assertTrue($recipe->requiresMutationAuthority());
        self::assertFalse($recipe->allowsAdditionalInstruction());
    }

    public function testStartRecipeDoesNotInventCurrentMutationAuthorityRequirement(): void
    {
        $recipe = OperatingPromptCatalog::bundled()->recipe('discovery-first');

        self::assertFalse($recipe->requiresTaskContext());
        self::assertFalse($recipe->requiresMutationAuthority());
        self::assertFalse($recipe->allowsAdditionalInstruction());
    }
}
