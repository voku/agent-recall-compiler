<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentRecallCompiler\Reflection\FutureWorkPromptBuilder;
use voku\AgentRecallCompiler\Reflection\FutureWorkScope;

final class DelegationArtifactShapeContractTest extends TestCase
{
    public function testSmallBoundedTaskRemainsAnExecutionPrompt(): void
    {
        $template = $this->template('production-ready-handoff');

        self::assertStringContainsString('Keep this recipe bounded to one execution contract', $template);
        self::assertStringContainsString('For a bounded ready implementation', $template);
        self::assertStringContainsString('copy-paste-ready L1 execution prompt for one bounded execution contract', $template);
    }

    public function testLargeResumableHandoffRoutesToDurableWorkPackage(): void
    {
        $template = $this->template('production-ready-handoff');

        self::assertStringContainsString('independently resumable milestones', $template);
        self::assertStringContainsString('do not serialize that durable backlog into one giant prompt', $template);
        self::assertStringContainsString('WORK_PACKAGE_REQUIRED', $template);
        self::assertStringContainsString('`todo-card-handoff` as the explicit durable-work-package construction path', $template);
        self::assertStringContainsString('do not create or persist cards implicitly', $template);
    }

    public function testMissingDurableTaskOwnerFailsClosed(): void
    {
        $productionReady = $this->template('production-ready-handoff');
        $workPackage = $this->template('todo-card-handoff');

        self::assertStringContainsString('If durable ownership is required but missing or conflicting, return BLOCKED', $productionReady);
        self::assertStringContainsString('if that authority is missing or conflicting, return BLOCKED', $workPackage);
        self::assertStringContainsString('instead of inventing a new task system', $workPackage);
    }

    public function testDurableWorkPackageMayExistBeforeExecutionEnvironmentIsKnown(): void
    {
        $template = $this->template('todo-card-handoff');

        self::assertStringContainsString('work-package candidates, not approved Contract/Run authority', $template);
        self::assertStringContainsString('Keep durable content portable and environment-agnostic where practical', $template);
        self::assertStringContainsString('do not persist transient host availability, giant environment snapshots, secrets, tokens, credentials', $template);
        self::assertStringContainsString('final host-specific execution belongs in a later explicit dispatch step', $template);
    }

    public function testExecutionDispatchRequiresBoundedCurrentEnvironmentEvidenceWhenNeeded(): void
    {
        $template = $this->template('execution-dispatch');

        self::assertStringContainsString('exact lineage to the selected durable task/work-package revision', $template);
        self::assertStringContainsString('current approved Contract/Run/stage authority', $template);
        self::assertStringContainsString('bounded current capability evidence supplied through an explicit owner boundary', $template);
        self::assertStringContainsString('do not accept arbitrary environment dumps, secrets, tokens, credentials, or host-selected task policy', $template);
        self::assertStringContainsString('return NOT_READY_TO_DELEGATE or BLOCKED', $template);
    }

    public function testExecutionDispatchFailsStaleAndRegeneratesOnDrift(): void
    {
        $template = $this->template('execution-dispatch');

        self::assertStringContainsString('Current repository, Contract/Run/stage, and bounded environment evidence win over stale dispatch text', $template);
        self::assertStringContainsString('require regeneration when they drift', $template);
        self::assertStringContainsString('not a durable work package, scheduler, environment inventory, host-selection policy', $template);
    }

    public function testFutureWorkInvestmentIsASelectionCeilingNotApprovalOrExecutionAuthority(): void
    {
        $prompt = (new FutureWorkPromptBuilder())->build(FutureWorkScope::PROJECT);

        self::assertStringContainsString('Prefer one highest-leverage direction over a broad wishlist', $prompt);
        self::assertStringContainsString('Do not manufacture backlog merely because time is available', $prompt);
        self::assertStringContainsString('or treat this reflection as authority to approve or execute a new task', $prompt);
    }

    private function template(string $id): string
    {
        $path = dirname(__DIR__) . '/skills/agent-recall-consumer/operating-prompts.json';
        $json = file_get_contents($path);
        if ($json === false) {
            throw new RuntimeException(sprintf('Unable to read operating prompt manifest at %s.', $path));
        }

        /** @var array{prompts: list<array{id: string, level: int, template: string}>} $manifest */
        $manifest = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        foreach ($manifest['prompts'] as $prompt) {
            if ($prompt['id'] === $id) {
                return $prompt['template'];
            }
        }

        throw new RuntimeException(sprintf('Operating prompt "%s" was not found.', $id));
    }
}
