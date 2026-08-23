<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentRecallCompiler\Rendering\OperatingPromptRenderer;

final class DelegationReadinessCompositionTest extends TestCase
{
    public function testShippedProductionHandoffRefusesKnownExternalUnreadiness(): void
    {
        $prompts = $this->shippedPrompts();
        $handoff = $prompts['production-ready-handoff'];

        self::assertStringContainsString('semantic owner', $handoff);
        self::assertStringContainsString('verification probe', $handoff);
        self::assertStringContainsString('delegated worker can satisfy it', $handoff);
        self::assertStringContainsString('NOT_READY_TO_DELEGATE', $handoff);
        self::assertStringContainsString('required hard prerequisite is missing', $handoff);
        self::assertStringContainsString('minimum-delivery contract', $handoff);
        self::assertStringContainsString('independent authorized milestones', $handoff);
        self::assertStringContainsString('completion report to be reconciled', $handoff);
    }

    public function testComposedL1StopContractsStayLocalUnlessEveryRemainingPathIsBlocked(): void
    {
        $markdown = (new OperatingPromptRenderer())->render([
            $this->fact('continue-until-done', 1, 'If the next step needs owner authority, stop with BLOCKED.'),
            $this->fact('retry-stop', 1, 'If validation fails twice, stop.'),
        ]);

        $shared = strpos($markdown, '### Shared execution-control semantics');
        $firstRecipe = strpos($markdown, '### continue-until-done (L1)');
        self::assertIsInt($shared);
        self::assertIsInt($firstRecipe);
        self::assertLessThan($firstRecipe, $shared);
        self::assertStringContainsString('continue remaining authorized independent work', $markdown);
        self::assertStringContainsString('every remaining authorized milestone depends on the blocker', $markdown);
        self::assertStringContainsString('unresolved required blocker still prevents the final success claim', $markdown);
    }

    public function testComposedL2AndL1ContractDefinesReadinessMinimumDeliveryAndEvidencePrecedence(): void
    {
        $markdown = (new OperatingPromptRenderer())->render([
            $this->fact('production-ready-handoff', 2, 'Build a handoff and re-ground missing evidence.'),
            $this->fact('continue-until-done', 1, 'Continue until Done When or BLOCKED.'),
        ]);

        self::assertStringContainsString('render `NOT_READY_TO_DELEGATE`', $markdown);
        self::assertStringContainsString('concrete delivery milestones with dependencies', $markdown);
        self::assertStringContainsString('blocker discovered during execution is local', $markdown);
        self::assertStringContainsString('`PRE_EXISTING`, `INTRODUCED`, and `UNKNOWN_ORIGIN`', $markdown);
        self::assertStringContainsString('completion report as a claim, not repository truth', $markdown);
        self::assertStringContainsString('apply the shared blocker-scope rule above', $markdown);
    }

    public function testShippedExecutionRecipesDoNotTurnRetryOrBlindSpotBlockersTaskGlobal(): void
    {
        $prompts = $this->shippedPrompts();

        self::assertStringContainsString('continue every remaining authorized independent milestone', $prompts['continue-until-done']);
        self::assertStringContainsString('continue any other authorized independent milestones', $prompts['execute-plan-with-blind-spot-check']);
        self::assertStringContainsString('stop retrying that path', $prompts['retry-stop']);
        self::assertStringContainsString('Do not turn that retry stop into a task-global stop', $prompts['retry-stop']);
        self::assertStringContainsString('PRE_EXISTING', $prompts['continue-until-done']);
        self::assertStringContainsString('actual artifacts', $prompts['execute-plan-with-blind-spot-check']);
    }

    /** @return array<string, string> */
    private function shippedPrompts(): array
    {
        $path = dirname(__DIR__) . '/skills/agent-recall-consumer/operating-prompts.json';
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded['prompts'] ?? null);
        $result = [];
        foreach ($decoded['prompts'] as $prompt) {
            self::assertIsArray($prompt);
            $id = $prompt['id'] ?? null;
            $template = $prompt['template'] ?? null;
            if (is_string($id) && is_string($template)) {
                $result[$id] = $template;
            }
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function fact(string $id, int $level, string $content): array
    {
        return [
            'type' => 'operating_prompt',
            'source_ref' => 'test://' . $id,
            'payload' => [
                'prompt_id' => $id,
                'level' => $level,
                'content' => $content,
            ],
        ];
    }
}
