<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Compilation;

use LogicException;
use voku\AgentRecallCompiler\CanonicalJson;
use voku\AgentRecallCompiler\ConstraintManifest;
use voku\AgentRecallCompiler\EvaluatedGuidance;
use voku\AgentRecallCompiler\ExclusionReason;
use voku\AgentRecallCompiler\GuidanceType;
use voku\AgentRecallCompiler\OperatingPromptRequest;
use voku\AgentRecallCompiler\Provider\RecallFact;
use voku\AgentRecallCompiler\Provider\RecallProvider;
use voku\AgentRecallCompiler\Provider\RecallProviderResult;
use voku\AgentRecallCompiler\RecallCompilationBlockedException;
use voku\AgentRecallCompiler\RecallDecisionEngine;
use voku\AgentRecallCompiler\RecallGuidance;
use voku\AgentRecallCompiler\RecallRejection;
use voku\AgentRecallCompiler\RecallResult;
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
        $selection = $this->preferLoadedCanonicalHomes($selection, $activeGuidance, $factResolution->facts);
        $this->assertSelectedGuidanceValidationEntryPointsAreLive($selection, $rootConfig->projectRoot);

        $snapshot = new CompilationSnapshot(CanonicalJson::digest($this->taskArray($task)), $snapshotProviders);
        $bundle = [
            'schema_version' => '1.0',
            'task' => $this->taskArray($task),
            'effective_scope' => $scopeResolution->toArray(),
            'snapshot' => $snapshot->toArray(),
            'selected_guidance' => array_map(static fn ($item): string => $item->id, $selection->selectedGuidance),
            // Persist the constraint metadata this compilation actually
            // selected. It is already in memory here; dropping it forced later
            // readers either to report it as unavailable or - worse - to look it
            // up in current Learning state and present today's answer as the
            // historical one.
            'selected_constraints' => array_map(static fn ($item): array => [
                'id' => $item->id,
                'engine' => $item->engine,
                'rule_identifier' => $item->ruleIdentifier,
                'source_proposal' => $item->sourceProposal,
                'scope' => $item->scope,
                'validation_commands' => $item->validationCommands,
                'status' => $item->status,
                'tags' => $item->tags,
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

    private function assertSelectedGuidanceValidationEntryPointsAreLive(RecallResult $selection, ?string $projectRoot): void
    {
        if ($projectRoot === null || trim($projectRoot) === '') {
            return;
        }

        foreach ($selection->selectedGuidance as $guidance) {
            foreach ($guidance->validation as $command) {
                $entryPoint = $this->projectLocalValidationEntryPoint($command);
                if ($entryPoint === null) {
                    continue;
                }
                if (is_file(rtrim($projectRoot, '/\\') . '/' . $entryPoint)) {
                    continue;
                }

                throw new RecallCompilationBlockedException(sprintf(
                    "Compilation blocked: selected guidance '%s' validation references missing project-local entry point '%s'.",
                    $guidance->id,
                    $entryPoint,
                ));
            }
        }
    }

    private function projectLocalValidationEntryPoint(string $command): ?string
    {
        if (preg_match('/^(?:php|bash|sh)\s+(\S+)(?:\s|$)/', trim($command), $matches) !== 1) {
            return null;
        }

        $entryPoint = $matches[1];
        if (
            str_starts_with($entryPoint, '-')
            || preg_match('/^(?:\.\/)?[A-Za-z0-9_.-]+(?:\/[A-Za-z0-9_.-]+)*$/D', $entryPoint) !== 1
        ) {
            return null;
        }

        if (str_starts_with($entryPoint, './')) {
            $entryPoint = substr($entryPoint, 2);
        }
        if ($entryPoint === '' || str_starts_with($entryPoint, 'vendor/')) {
            return null;
        }
        foreach (explode('/', $entryPoint) as $segment) {
            if ($segment === '..') {
                return null;
            }
        }
        if (!str_contains($entryPoint, '/') && !str_ends_with($entryPoint, '.php') && !str_ends_with($entryPoint, '.sh')) {
            return null;
        }

        return $entryPoint;
    }

    /**
     * @param list<RecallGuidance> $activeGuidance
     * @param list<array<string, mixed>> $facts resolved canonical fact projection
     */
    private function preferLoadedCanonicalHomes(RecallResult $selection, array $activeGuidance, array $facts): RecallResult
    {
        $loadedCanonicalSources = [];
        foreach ($facts as $fact) {
            $type = $fact['type'] ?? null;
            if (!is_string($type) || !in_array($type, [GuidanceType::MEMORY->value, GuidanceType::SKILL->value], true)) {
                continue;
            }
            $payload = $fact['payload'] ?? null;
            if (!is_array($payload)) {
                continue;
            }
            $sourceRef = $payload['canonical_source_ref'] ?? null;
            $sourceHash = $payload['source_sha256'] ?? null;
            if (!is_string($sourceRef) || trim($sourceRef) === '' || !is_string($sourceHash) || trim($sourceHash) === '') {
                continue;
            }
            $loadedCanonicalSources[$this->canonicalSourceKey($type, $sourceRef, $sourceHash)] = true;
        }

        if ($loadedCanonicalSources === []) {
            return $selection;
        }

        $selectedIds = [];
        foreach ($selection->selectedGuidance as $guidance) {
            $selectedIds[$guidance->id] = true;
        }

        $suppressedIds = [];
        foreach ($activeGuidance as $guidance) {
            if (
                $guidance->status !== 'applied'
                || !isset($selectedIds[$guidance->id])
                || $guidance->appliedTargetSourceRef === null
                || $guidance->appliedTargetContentHash === null
            ) {
                continue;
            }

            $type = GuidanceType::fromTargetType($guidance->targetType, $guidance->id);
            if ($type === GuidanceType::CONSTRAINT) {
                continue;
            }

            $key = $this->canonicalSourceKey(
                $type->value,
                $guidance->appliedTargetSourceRef,
                $guidance->appliedTargetContentHash,
            );
            if (isset($loadedCanonicalSources[$key])) {
                $suppressedIds[$guidance->id] = true;
            }
        }

        if ($suppressedIds === []) {
            return $selection;
        }

        $selectedGuidance = array_values(array_filter(
            $selection->selectedGuidance,
            static fn (RecallGuidance $guidance): bool => !isset($suppressedIds[$guidance->id]),
        ));

        $evaluatedGuidance = array_map(
            static function (EvaluatedGuidance $evaluated) use ($suppressedIds): EvaluatedGuidance {
                if (!isset($suppressedIds[$evaluated->guidanceId]) || !$evaluated->selected) {
                    return $evaluated;
                }

                return new EvaluatedGuidance(
                    $evaluated->guidanceId,
                    $evaluated->guidanceType,
                    true,
                    false,
                    null,
                    ExclusionReason::CANONICAL_HOME_LOADED,
                    $evaluated->taskFiles,
                    $evaluated->sourceProposal,
                );
            },
            $selection->evaluatedGuidance,
        );

        $outcomeStats = $selection->outcomeStats;
        foreach (array_keys($suppressedIds) as $guidanceId) {
            unset($outcomeStats[$guidanceId]);
        }

        return new RecallResult(
            $selectedGuidance,
            $selection->selectedRejections,
            $selection->warnings,
            $selection->selectedConstraints,
            $outcomeStats,
            $evaluatedGuidance,
        );
    }

    private function canonicalSourceKey(string $type, string $sourceRef, string $sha256): string
    {
        return CanonicalJson::digest([
            'type' => $type,
            'source_ref' => ltrim(str_replace('\\', '/', trim($sourceRef)), '/'),
            'sha256' => strtolower(trim($sha256)),
        ]);
    }

    /** @return array<string, mixed> */
    private function taskArray(TaskBrief $task): array
    {
        $data = [
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
        ];
        if ($task->operatingPrompts !== []) {
            $data['operating_prompts'] = array_map(
                static fn (OperatingPromptRequest $request): array => $request->toArray(),
                $task->operatingPrompts,
            );
        }

        return $data;
    }
}
