<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Compilation;

use LogicException;
use voku\AgentRecallCompiler\CanonicalJson;
use voku\AgentRecallCompiler\ConstraintManifest;
use voku\AgentRecallCompiler\OperatingPromptRequest;
use voku\AgentRecallCompiler\Provider\RecallFact;
use voku\AgentRecallCompiler\Provider\RecallProvider;
use voku\AgentRecallCompiler\Provider\RecallProviderResult;
use voku\AgentRecallCompiler\RecallDecisionEngine;
use voku\AgentRecallCompiler\RecallGuidance;
use voku\AgentRecallCompiler\RecallRejection;
use voku\AgentRecallCompiler\RecallRetirement;
use voku\AgentRecallCompiler\RecallRootConfig;
use voku\AgentRecallCompiler\TaskBrief;

/**
 * Deterministic orchestration only. Source formats stay behind providers and
 * all generated prompts remain renderings of the returned bundle.
 */
final class RecallCompilationService
{
    /** @param list<RecallProvider> $providers */
    public function __construct(
        private readonly array $providers,
        private readonly RecallDecisionEngine $decisionEngine = new RecallDecisionEngine(),
    ) {
    }

    public function compile(TaskBrief $task, RecallRootConfig $rootConfig): RecallCompilation
    {
        $providers = $this->providers;
        usort($providers, static fn (RecallProvider $left, RecallProvider $right): int => strcmp($left->manifest()->id, $right->manifest()->id));
        $this->assertUniqueProviderIds($providers);

        // The map is the only provider allowed to enlarge the task's effective
        // file scope. Resolve it first so path-scoped documents and guidance
        // can see exact primary/contract/caller/test files without presenting
        // dependency-only context as an intended edit.
        $precomputedResults = [];
        $mapFacts = [];
        foreach ($providers as $provider) {
            if ($provider->manifest()->id !== 'agent-map') {
                continue;
            }
            $result = $provider->collect($task, $rootConfig);
            $precomputedResults['agent-map'] = $result;
            array_push($mapFacts, ...$result->facts);
        }
        $mapFactResolution = (new FactResolver())->resolve($mapFacts);
        $scopeResolution = (new TaskScopeResolver())->resolve($task, $mapFactResolution->facts);

        $activeGuidance = [];
        $rejectedGuidance = [];
        $outcomes = [];
        $constraints = [];
        $retiredProposals = [];
        $factCandidates = [];
        $snapshotProviders = [];

        foreach ($providers as $provider) {
            $manifest = $provider->manifest();
            $providerTask = $manifest->id === 'task-context' ? $task : $scopeResolution->effectiveTask;
            $result = $precomputedResults[$manifest->id] ?? $provider->collect($providerTask, $rootConfig);
            $this->collectResult(
                $result,
                $activeGuidance,
                $rejectedGuidance,
                $outcomes,
                $constraints,
                $retiredProposals,
                $factCandidates,
            );
            $snapshotProviders[] = ['manifest' => $manifest->toArray(), 'source_digest' => $result->sourceDigest];
        }

        $factResolution = (new FactResolver())->resolve($factCandidates);
        $scopeResolution = (new TaskScopeResolver())->resolve($task, $factResolution->facts);
        $selection = $this->decisionEngine->decide(
            $scopeResolution->effectiveTask,
            $activeGuidance,
            $rejectedGuidance,
            $outcomes,
            $constraints,
            $retiredProposals,
        );
        $snapshot = new CompilationSnapshot(CanonicalJson::digest($this->taskArray($task)), $snapshotProviders);
        $bundle = [
            'schema_version' => '1.0',
            'task' => $this->taskArray($task),
            'effective_scope' => $scopeResolution->toArray(),
            'snapshot' => $snapshot->toArray(),
            'selected_guidance' => array_map(static fn ($item): string => $item->id, $selection->selectedGuidance),
            'selected_constraints' => array_map(static fn ($item): array => [
                'id' => $item->id,
                'engine' => $item->engine,
                'rule_identifier' => $item->ruleIdentifier,
                'source_proposal' => $item->sourceProposal,
            ], $selection->selectedConstraints),
            'selected_rejections' => array_map(static fn ($item): string => $item->id, $selection->selectedRejections),
            'evaluated_guidance' => array_map(static fn ($item): array => $item->toArray(), $selection->evaluatedGuidance),
            'outcome_stats' => $selection->outcomeStats,
            'warnings' => $selection->warnings,
            'fact_decisions' => $factResolution->decisions,
            'facts' => $factResolution->facts,
        ];

        /** @var array<string, mixed> $canonicalBundle */
        $canonicalBundle = CanonicalJson::normalize($bundle);

        return new RecallCompilation(
            result: $selection,
            snapshot: $snapshot,
            bundle: $canonicalBundle,
            facts: $factResolution->facts,
            factDecisions: $factResolution->decisions,
            effectiveTask: $scopeResolution->effectiveTask,
            effectiveScope: $scopeResolution->toArray(),
        );
    }

    /** @param list<RecallProvider> $providers */
    private function assertUniqueProviderIds(array $providers): void
    {
        $providerIds = [];
        foreach ($providers as $provider) {
            $providerId = $provider->manifest()->id;
            if (isset($providerIds[$providerId])) {
                throw new LogicException('Recall provider ID is registered more than once: ' . $providerId);
            }
            $providerIds[$providerId] = true;
        }
    }

    /**
     * @param list<RecallGuidance> $activeGuidance
     * @param list<RecallRejection> $rejectedGuidance
     * @param list<array<string, mixed>> $outcomes
     * @param list<ConstraintManifest> $constraints
     * @param list<RecallRetirement> $retiredProposals
     * @param list<RecallFact> $factCandidates
     */
    private function collectResult(
        RecallProviderResult $result,
        array &$activeGuidance,
        array &$rejectedGuidance,
        array &$outcomes,
        array &$constraints,
        array &$retiredProposals,
        array &$factCandidates,
    ): void {
        array_push($activeGuidance, ...$result->activeGuidance);
        array_push($rejectedGuidance, ...$result->rejectedGuidance);
        array_push($outcomes, ...$result->outcomes);
        array_push($constraints, ...$result->constraints);
        array_push($retiredProposals, ...$result->retiredProposals);
        array_push($factCandidates, ...$result->facts);
    }

    /** @return array<string, mixed> */
    private function taskArray(TaskBrief $task): array
    {
        return [
            'id' => $task->id,
            'description' => $task->description,
            'files' => $task->files,
            'scopes' => $task->scopes,
            'non_goals' => $task->nonGoals,
            'validation' => $task->validation,
            'status' => $task->status,
            'revision' => $task->revision,
            'source_path' => $task->sourcePath,
            'tags' => $task->tags,
            'behavior_anchors' => $task->behaviorAnchors,
            'targets' => $task->targets,
            'operating_prompts' => array_map(
                static fn (OperatingPromptRequest $request): array => $request->toArray(),
                $task->operatingPrompts,
            ),
        ];
    }
}
