<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Provider;

use voku\AgentRecallCompiler\CanonicalJson;
use voku\AgentRecallCompiler\RecallGuidance;
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
        $activeGuidance = $this->withAppliedTargetEvidence(
            $rootConfig->root,
            $this->repository->loadActiveGuidance($rootConfig->root),
        );
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

    /**
     * @param list<RecallGuidance> $guidance
     * @return list<RecallGuidance>
     */
    private function withAppliedTargetEvidence(string $root, array $guidance): array
    {
        foreach ($guidance as $index => $item) {
            if ($item->status !== 'applied') {
                continue;
            }

            $path = rtrim($root, '/\\') . '/proposals/applied/' . $item->id . '.json';
            if (!is_file($path)) {
                continue;
            }
            $content = file_get_contents($path);
            if ($content === false) {
                continue;
            }
            $data = json_decode($content, true);
            $appliedValidation = is_array($data) ? ($data['applied_validation'] ?? null) : null;
            if (!is_array($appliedValidation)) {
                continue;
            }

            $sourceRef = $appliedValidation['target_source_ref'] ?? null;
            $contentHash = $appliedValidation['target_content_hash'] ?? null;
            if (!is_string($sourceRef) || trim($sourceRef) === '' || !is_string($contentHash) || trim($contentHash) === '') {
                continue;
            }

            $guidance[$index] = new RecallGuidance(
                $item->id,
                $item->action,
                $item->targetType,
                $item->target,
                $item->scope,
                $item->old,
                $item->new,
                $item->reason,
                $item->boundary,
                $item->validation,
                $item->status,
                $item->tags,
                $item->patternKey,
                str_replace('\\', '/', trim($sourceRef)),
                strtolower(trim($contentHash)),
            );
        }

        return $guidance;
    }
}
