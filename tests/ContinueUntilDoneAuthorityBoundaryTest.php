<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;

final class ContinueUntilDoneAuthorityBoundaryTest extends TestCase
{
    public function testAutonomousContinuationCannotSelfConfirmExternalAuthorityGates(): void
    {
        $template = $this->template();

        self::assertStringContainsString('internal continuation check, not approval or external confirmation', $template);
        self::assertStringContainsString('Never satisfy a human, owner, reviewer, or accepted-risk decision by confirming it yourself', $template);
        self::assertStringContainsString('Continue automatically only while the current authority remains valid', $template);
        self::assertStringContainsString('HUMAN_DECISION_REQUIRED', $template);
        self::assertStringContainsString('approved Goal, acceptance criteria, scope or non-goals', $template);
        self::assertStringContainsString('{{done_condition}} is satisfied by observed evidence', $template);
    }

    public function testMultipleTodosAreSlicedBeforeAutomaticContinuation(): void
    {
        $template = $this->template();

        self::assertStringContainsString('multiple TODOs or independently executable work items', $template);
        self::assertStringContainsString('define the bounded slices before implementation', $template);
        self::assertStringContainsString('objective, dependencies, expected change or artifact, and validation checkpoint', $template);
        self::assertStringContainsString('After each slice', $template);
        self::assertStringContainsString('continue every remaining authorized independent slice', $template);
        self::assertStringContainsString('Unresolved required blockers still prevent final success', $template);
    }

    public function testCatalogUsesDoneConditionForResumableCheckpointInsteadOfSecondRecipe(): void
    {
        $metadata = json_decode(
            (string) file_get_contents(dirname(__DIR__) . '/resources/skills/agent-recall-consumer/operating-prompts.metadata.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($metadata['recipes'] ?? null);

        $ids = [];
        $continueRecipe = null;
        foreach ($metadata['recipes'] as $recipe) {
            if (!is_array($recipe)) {
                continue;
            }

            if (is_string($recipe['id'] ?? null)) {
                $ids[] = $recipe['id'];
            }
            if (($recipe['id'] ?? null) === 'continue-until-done') {
                $continueRecipe = $recipe;
            }
        }

        self::assertNotNull($continueRecipe);
        self::assertNotContains('continue-to-checkpoint', $ids);
        self::assertStringContainsString('resumable checkpoint', (string) ($continueRecipe['description'] ?? ''));

        $arguments = is_array($continueRecipe['arguments'] ?? null) ? $continueRecipe['arguments'] : [];
        $doneCondition = null;
        foreach ($arguments as $argument) {
            if (is_array($argument) && ($argument['name'] ?? null) === 'done_condition') {
                $doneCondition = $argument;
                break;
            }
        }

        self::assertNotNull($doneCondition);
        self::assertStringContainsString('safe resumability checkpoint', (string) ($doneCondition['description'] ?? ''));

        $examples = is_array($doneCondition['examples'] ?? null) ? $doneCondition['examples'] : [];
        $exampleText = implode("\n", array_filter($examples, 'is_string'));
        self::assertStringContainsString('closing the current chat', $exampleText);
        self::assertStringContainsString('durable authoritative artifacts', $exampleText);
    }

    private function template(): string
    {
        $manifest = json_decode(
            (string) file_get_contents(dirname(__DIR__) . '/resources/skills/agent-recall-consumer/operating-prompts.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($manifest['prompts'] ?? null);
        foreach ($manifest['prompts'] as $prompt) {
            if (is_array($prompt) && ($prompt['id'] ?? null) === 'continue-until-done') {
                self::assertIsString($prompt['template'] ?? null);

                return $prompt['template'];
            }
        }

        self::fail('continue-until-done prompt not found.');
    }
}
