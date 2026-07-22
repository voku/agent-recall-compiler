<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Provider;

use voku\AgentRecallCompiler\CanonicalJson;
use voku\AgentRecallCompiler\RecallRepository;
use voku\AgentRecallCompiler\RecallRootConfig;
use voku\AgentRecallCompiler\TaskBrief;

/**
 * Transitional adapter around the existing agent-learning filesystem layout.
 * It is the only place that knows the legacy loader; the compiler only sees a
 * provider result and can accept future providers through the same seam.
 */
final class LearningRecallProvider implements RecallProvider
{
    public function __construct(private readonly RecallRepository $repository = new RecallRepository())
    {
    }

    public function manifest(): RecallProviderManifest
    {
        return new RecallProviderManifest('agent-learning', '1.0', ['proposals/', 'constraints/', 'history/']);
    }

    public function collect(TaskBrief $task, RecallRootConfig $rootConfig): RecallProviderResult
    {
        $activeGuidance = $this->repository->loadActiveGuidance($rootConfig->root);
        $rejectedGuidance = $this->repository->loadRejectedGuidance($rootConfig->root);
        $outcomes = $this->repository->loadOutcomes($rootConfig->root);
        $constraints = $this->repository->loadConstraintManifests($rootConfig->root);
        $retiredProposals = $this->repository->loadRetiredProposals($rootConfig->root);

        $digestInput = [
            'active_guidance' => array_map(static fn ($item): string => serialize($item), $activeGuidance),
            'rejected_guidance' => array_map(static fn ($item): string => serialize($item), $rejectedGuidance),
            'outcomes' => $outcomes,
            'constraints' => array_map(static fn ($item): string => serialize($item), $constraints),
            'retired_proposals' => array_map(static fn ($item): string => serialize($item), $retiredProposals),
        ];

        return new RecallProviderResult(
            CanonicalJson::digest($digestInput),
            activeGuidance: $activeGuidance,
            rejectedGuidance: $rejectedGuidance,
            outcomes: $outcomes,
            constraints: $constraints,
            retiredProposals: $retiredProposals,
        );
    }
}
