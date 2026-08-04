<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Verification;

use LogicException;
use voku\AgentMap\Context\EditContextPlan;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentRecallCompiler\CanonicalJson;
use voku\AgentRecallCompiler\RecallResult;
use voku\AgentRecallCompiler\TaskBrief;

/** Compiles public verification declarations and separate verifier material. */
final readonly class VerificationPlanCompiler
{
    private const GENERATOR_NAME = 'agent-recall-compiler';

    public function __construct(
        private AgentMapIndex $map,
        private KnowledgeProbeGenerator $probeGenerator = new KnowledgeProbeGenerator(),
        private EvidenceChecklistGenerator $checklistGenerator = new EvidenceChecklistGenerator(),
        private ObjectiveGateCompiler $gateCompiler = new ObjectiveGateCompiler(),
    ) {
    }

    public function compile(
        TaskBrief $task,
        EditContextPlan $context,
        RecallResult $recall,
    ): CompiledVerificationPlan {
        if (!hash_equals($this->map->mapDigest(), $context->mapDigest)) {
            throw new LogicException('Verification context does not match the selected agent-map snapshot.');
        }

        $generated = $this->probeGenerator->generate($this->map, $task, $context);
        $checklist = $this->checklistGenerator->generate($context, $recall);
        $gates = $this->gateCompiler->compile($task, $context);
        $generator = [
            'name' => self::GENERATOR_NAME,
            'version' => $generated->generatorVersion,
            'seed_sha256' => $generated->seedSha256,
        ];

        $plan = new VerificationPlan(
            taskId: $task->id,
            target: $context->resolvedTarget->id,
            mapDigest: $context->mapDigest,
            analysisFingerprint: $this->map->fingerprint?->toArray(),
            knowledgeProbes: $generated->probes,
            omittedProbeCandidates: $generated->omittedCandidates,
            checklist: $checklist,
            objectiveGates: $gates,
            requiredEvidenceTypes: $this->requiredEvidenceTypes($generated->probes, $checklist, $gates),
            generator: $generator,
        );
        $planSha256 = 'sha256:' . hash('sha256', CanonicalJson::pretty($plan->toArray()));
        $verificationKey = new VerificationKey(
            planSha256: $planSha256,
            target: $context->resolvedTarget->id,
            mapDigest: $context->mapDigest,
            probes: $generated->answers,
            generator: $generator,
        );

        return new CompiledVerificationPlan($plan, $verificationKey);
    }

    /**
     * @param list<KnowledgeProbe> $probes
     * @param list<ChecklistItem> $checklist
     * @param list<ObjectiveGate> $gates
     * @return non-empty-list<string>
     */
    private function requiredEvidenceTypes(array $probes, array $checklist, array $gates): array
    {
        /** @var array<string, true> $types */
        $types = [];
        foreach ($probes as $probe) {
            foreach ($probe->requiredEvidenceTypes as $type) {
                $types[$type] = true;
            }
        }
        foreach ($checklist as $item) {
            $types[$item->verifier] = true;
        }
        foreach ($gates as $gate) {
            $types[match ($gate->kind) {
                'post_edit_map_fresh', 'target_resolvable' => 'agent_map',
                default => 'command_result',
            }] = true;
        }

        $result = array_keys($types);
        sort($result, SORT_STRING);
        if ($result === []) {
            throw new LogicException('A verification plan must declare at least one evidence type.');
        }

        return $result;
    }
}
