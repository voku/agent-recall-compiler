<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Verification;

use voku\AgentMap\Context\CodeSlice;
use voku\AgentMap\Context\EditContextPlan;
use voku\AgentRecallCompiler\ConstraintManifest;
use voku\AgentRecallCompiler\RecallResult;

/** Declares evidence obligations from the actual selected edit context. */
final readonly class EvidenceChecklistGenerator
{
    /** @return list<ChecklistItem> */
    public function generate(EditContextPlan $context, RecallResult $recall): array
    {
        /** @var array<string, ChecklistItem> $items */
        $items = [];
        foreach ($context->slices as $slice) {
            foreach ($slice->roles as $role) {
                $item = $this->sliceItem($context, $slice, $role);
                if ($item !== null) {
                    $items[$item->id] = $item;
                }
            }
        }

        foreach ($context->blindSpots as $blindSpot) {
            $sourceRef = ($blindSpot->path ?? 'unknown') . ':' . (string) ($blindSpot->line ?? 0);
            $evidenceIds = $blindSpot->evidenceIds === []
                ? ['blind-spot:' . hash('sha256', $blindSpot->kind . "\0" . $sourceRef . "\0" . $blindSpot->message)]
                : $this->sortedUnique($blindSpot->evidenceIds);
            $id = 'check:blind-spot:' . substr(hash('sha256', $blindSpot->kind . "\0" . $sourceRef), 0, 12);
            $items[$id] = new ChecklistItem(
                id: $id,
                statement: sprintf('The `%s` map blind spot at %s was explicitly investigated.', $blindSpot->kind, $sourceRef),
                verifier: 'human_review',
                evidenceIds: $evidenceIds,
                provenance: [
                    'kind' => $blindSpot->kind,
                    'message' => $blindSpot->message,
                    'source_ref' => $sourceRef,
                ],
            );
        }

        foreach ($context->omitted as $omitted) {
            $id = 'check:omitted-context:' . substr(hash('sha256', $omitted->symbolId . "\0" . $omitted->role . "\0" . $omitted->reason), 0, 12);
            $items[$id] = new ChecklistItem(
                id: $id,
                statement: sprintf('The omitted `%s` context for `%s` was acknowledged where relevant.', $omitted->role, $omitted->symbolId),
                verifier: 'human_review',
                evidenceIds: [$omitted->symbolId],
                provenance: [
                    'symbol_id' => $omitted->symbolId,
                    'role' => $omitted->role,
                    'reason' => $omitted->reason,
                ],
            );
        }

        foreach ($recall->selectedConstraints as $constraint) {
            $item = $this->constraintItem($constraint);
            $items[$item->id] = $item;
        }

        ksort($items, SORT_STRING);

        return array_values($items);
    }

    private function sliceItem(EditContextPlan $context, CodeSlice $slice, string $role): ?ChecklistItem
    {
        $sourceRef = sprintf('%s:%d-%d', $slice->path, $slice->lineStart, $slice->lineEnd);
        $evidenceIds = $slice->evidenceIds === []
            ? [$slice->sourceSha256]
            : $this->sortedUnique($slice->evidenceIds);
        $provenance = [
            'source_ref' => $sourceRef,
            'source_sha256' => $slice->sourceSha256,
            'roles' => $slice->roles,
            'reasons' => $slice->reasons,
        ];

        return match ($role) {
            'primary' => new ChecklistItem(
                id: 'check:target-resolvable',
                statement: sprintf('The primary target `%s` remains resolvable and conflict-free.', $context->resolvedTarget->id),
                verifier: 'agent_map',
                evidenceIds: [$context->resolvedTarget->id],
                provenance: [
                    ...$provenance,
                    'map_digest' => $context->mapDigest,
                    'target' => $context->resolvedTarget->id,
                ],
            ),
            'contract' => new ChecklistItem(
                id: 'check:contract:' . substr(hash('sha256', $sourceRef), 0, 12),
                statement: sprintf('The contract at %s was checked for compatibility.', $sourceRef),
                verifier: 'evidence_trace',
                evidenceIds: $evidenceIds,
                provenance: $provenance,
            ),
            'change_candidate' => new ChecklistItem(
                id: 'check:caller:' . substr(hash('sha256', $sourceRef), 0, 12),
                statement: sprintf('The change-candidate caller at %s was checked or adapted.', $sourceRef),
                verifier: 'patch_evidence',
                evidenceIds: $evidenceIds,
                provenance: $provenance,
            ),
            'verification' => new ChecklistItem(
                id: 'check:verification:' . substr(hash('sha256', $sourceRef), 0, 12),
                statement: sprintf('The verification context at %s was run, updated, or explicitly justified.', $sourceRef),
                verifier: 'command_result',
                evidenceIds: $evidenceIds,
                provenance: $provenance,
            ),
            default => null,
        };
    }

    private function constraintItem(ConstraintManifest $constraint): ChecklistItem
    {
        $id = 'check:constraint:' . substr(hash('sha256', $constraint->id), 0, 12);

        return new ChecklistItem(
            id: $id,
            statement: sprintf('Hard constraint `%s` and rule `%s` were verified with the declared commands.', $constraint->id, $constraint->ruleIdentifier),
            verifier: 'command_result',
            evidenceIds: [$constraint->id],
            provenance: [
                'engine' => $constraint->engine,
                'rule_identifier' => $constraint->ruleIdentifier,
                'source_proposal' => $constraint->sourceProposal,
                'validation_commands' => $constraint->validationCommands,
            ],
        );
    }

    /**
     * @param list<string> $values
     * @return non-empty-list<string>
     */
    private function sortedUnique(array $values): array
    {
        $values = array_values(array_unique($values));
        sort($values, SORT_STRING);

        if ($values === []) {
            throw new \LogicException('Evidence identifiers must not be empty.');
        }

        return $values;
    }
}
