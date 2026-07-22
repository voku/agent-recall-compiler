<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Compilation;

use voku\AgentRecallCompiler\CanonicalJson;
use voku\AgentRecallCompiler\RecallDecisionEngine;
use voku\AgentRecallCompiler\RecallRootConfig;
use voku\AgentRecallCompiler\TaskBrief;
use voku\AgentRecallCompiler\Provider\RecallProvider;

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

        $activeGuidance = [];
        $rejectedGuidance = [];
        $outcomes = [];
        $constraints = [];
        $retiredProposals = [];
        $factCandidates = [];
        $snapshotProviders = [];
        $providerIds = [];

        foreach ($providers as $provider) {
            $manifest = $provider->manifest();
            if (isset($providerIds[$manifest->id])) {
                throw new \LogicException('Recall provider ID is registered more than once: ' . $manifest->id);
            }
            $providerIds[$manifest->id] = true;
            $result = $provider->collect($task, $rootConfig);
            array_push($activeGuidance, ...$result->activeGuidance);
            array_push($rejectedGuidance, ...$result->rejectedGuidance);
            array_push($outcomes, ...$result->outcomes);
            array_push($constraints, ...$result->constraints);
            array_push($retiredProposals, ...$result->retiredProposals);
            foreach ($result->facts as $fact) {
                $factCandidates[] = $fact;
            }
            $snapshotProviders[] = ['manifest' => $manifest->toArray(), 'source_digest' => $result->sourceDigest];
        }

        $factResolution = (new FactResolver())->resolve($factCandidates);
        $selection = $this->decisionEngine->decide($task, $activeGuidance, $rejectedGuidance, $outcomes, $constraints, $retiredProposals);
        $snapshot = new CompilationSnapshot(CanonicalJson::digest($this->taskArray($task)), $snapshotProviders);
        $bundle = [
            'schema_version' => '1.0',
            'task' => $this->taskArray($task),
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

        return new RecallCompilation($selection, $snapshot, $canonicalBundle, $factResolution->facts, $factResolution->decisions);
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
        ];
    }
}
