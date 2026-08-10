<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Context;

use LogicException;
use voku\AgentRecallCompiler\EvaluatedGuidance;
use voku\AgentRecallCompiler\RecallResult;
use voku\AgentRecallCompiler\TaskBrief;

/**
 * Deterministically explains why compiled context was selected and how it may be used.
 *
 * It never reconstructs implementation rationale. Every explanation is derived from
 * provider facts or a selection decision that already exists in the compilation.
 *
 * @phpstan-type ExplainState 'verified'|'inferred'|'unknown'|'blocked'
 * @phpstan-type ExplainItem array{
 *     id: string,
 *     kind: string,
 *     what: string,
 *     why: string,
 *     how: string,
 *     authority: string,
 *     use: string,
 *     state: ExplainState,
 *     selected: bool,
 *     source_ref: string|null,
 *     evidence_ids: list<string>,
 *     why_not?: string
 * }
 */
final readonly class ContextExplainProjector
{
    /** @var array<string, string> */
    private const array MAP_ROLE_USE = [
        'primary' => 'implementation_candidate',
        'contract' => 'compatibility_contract',
        'change_candidate' => 'inspect_and_edit_if_required',
        'verification' => 'verification',
        'dependency' => 'context_only_do_not_edit_from_selection_alone',
        'type_definition' => 'context_only_do_not_edit_from_selection_alone',
    ];

    /**
     * @param list<array<string, mixed>> $facts
     * @return list<ExplainItem>
     */
    public function project(TaskBrief $task, array $facts, RecallResult $result): array
    {
        /** @var array<string, ExplainItem> $items */
        $items = [];
        foreach ($facts as $fact) {
            foreach ($this->explainFact($task, $fact) as $item) {
                $items[$item['id']] = $item;
            }
        }
        foreach ($result->evaluatedGuidance as $evaluated) {
            $item = $this->explainGuidance($evaluated);
            $items[$item['id']] = $item;
        }
        ksort($items, SORT_STRING);

        return array_values($items);
    }

    /**
     * @param array<string, mixed> $fact
     * @return list<ExplainItem>
     */
    private function explainFact(TaskBrief $task, array $fact): array
    {
        return match ($fact['type'] ?? null) {
            'edit_context' => $this->explainEditContext($fact),
            'project_capabilities' => $this->explainCapabilities($fact),
            'adr', 'skill' => [$this->explainDocument($task, $fact)],
            'operating_prompt' => $this->explainOperatingPrompt($fact),
            default => [],
        };
    }

    /**
     * @param array<string, mixed> $fact
     * @return list<ExplainItem>
     */
    private function explainEditContext(array $fact): array
    {
        $payload = $this->payload($fact);
        $sourceRef = $this->string($fact['source_ref'] ?? null);
        $items = [];

        foreach ($this->arrays($payload['slices'] ?? []) as $index => $slice) {
            $path = $this->string($slice['path'] ?? null);
            if ($path === null) {
                continue;
            }
            $roles = $this->strings($slice['roles'] ?? []);
            $reasons = $this->strings($slice['reasons'] ?? []);
            $lineStart = is_int($slice['line_start'] ?? null) ? $slice['line_start'] : null;
            $lineEnd = is_int($slice['line_end'] ?? null) ? $slice['line_end'] : null;
            $what = $path . ($lineStart !== null && $lineEnd !== null ? ':' . $lineStart . '-' . $lineEnd : '');
            $unknownRoles = array_diff($roles, array_keys(self::MAP_ROLE_USE));
            $state = $roles === [] || $unknownRoles !== [] ? 'unknown' : 'verified';
            $why = $reasons !== []
                ? implode('; ', $reasons)
                : ($roles === []
                    ? 'agent-map selected this source slice but exposed no recognized role or reason.'
                    : 'agent-map selected this source slice as ' . implode(', ', $roles) . ' context for the current target.');
            $items[] = $this->item(
                id: 'map-slice:' . hash('sha256', $what . "\0" . implode(',', $roles) . "\0" . (string) $index),
                kind: 'map_slice',
                what: $what,
                why: $why,
                how: 'agent-map EditContextPlan' . ($roles === [] ? '' : ' role(s): ' . implode(', ', $roles)),
                authority: 'repository_source_via_agent_map',
                use: $unknownRoles === [] ? $this->mapUse($roles) : 'context_only_until_verified',
                state: $state,
                selected: true,
                sourceRef: $sourceRef,
                evidenceIds: $this->strings($slice['evidence_ids'] ?? []),
            );
        }

        foreach ($this->arrays($payload['blind_spots'] ?? []) as $index => $blindSpot) {
            $kind = $this->string($blindSpot['kind'] ?? null) ?? 'unknown';
            $path = $this->string($blindSpot['path'] ?? null);
            $line = is_int($blindSpot['line'] ?? null) ? $blindSpot['line'] : null;
            $what = ($path ?? 'blind-spot:' . $kind) . ($path !== null && $line !== null ? ':' . $line : '');
            $items[] = $this->item(
                id: 'map-blind-spot:' . hash('sha256', $kind . "\0" . $what . "\0" . (string) $index),
                kind: 'map_blind_spot',
                what: $what,
                why: $this->string($blindSpot['message'] ?? null) ?? 'agent-map reported an unresolved blind spot.',
                how: 'agent-map reported a static-analysis blind spot while constructing EditContextPlan.',
                authority: 'derived_navigation',
                use: 'investigate_before_claiming_complete',
                state: 'unknown',
                selected: true,
                sourceRef: $sourceRef,
                evidenceIds: $this->strings($blindSpot['evidence_ids'] ?? []),
            );
        }

        foreach ($this->arrays($payload['omitted'] ?? []) as $index => $omitted) {
            $symbol = $this->string($omitted['symbol_id'] ?? null) ?? 'unknown';
            $role = $this->string($omitted['role'] ?? null) ?? 'unknown';
            $items[] = $this->item(
                id: 'map-omitted:' . hash('sha256', $symbol . "\0" . $role . "\0" . (string) $index),
                kind: 'map_omission',
                what: $symbol,
                why: 'The candidate was considered while constructing bounded edit context.',
                how: 'agent-map EditContextPlan omission for role ' . $role . '.',
                authority: 'derived_navigation',
                use: 'investigate_if_relevant',
                state: 'unknown',
                selected: false,
                sourceRef: $sourceRef,
                evidenceIds: [],
                whyNot: $this->string($omitted['reason'] ?? null) ?? 'agent-map omitted this candidate from bounded context.',
            );
        }

        return $items;
    }

    /** @param list<string> $roles */
    private function mapUse(array $roles): string
    {
        foreach (['primary', 'contract', 'change_candidate', 'verification'] as $role) {
            if (in_array($role, $roles, true)) {
                return self::MAP_ROLE_USE[$role];
            }
        }
        if (array_intersect(['dependency', 'type_definition'], $roles) !== []) {
            return 'context_only_do_not_edit_from_selection_alone';
        }

        return 'context_only_until_verified';
    }

    /**
     * @param array<string, mixed> $fact
     * @return list<ExplainItem>
     */
    private function explainCapabilities(array $fact): array
    {
        $payload = $this->payload($fact);
        $authority = $this->string($fact['authority'] ?? null) ?? 'project_metadata';
        $sourceRef = $this->string($fact['source_ref'] ?? null);
        $items = [];

        $runtime = $this->string($payload['runtime_constraint'] ?? null);
        if ($runtime !== null) {
            $items[] = $this->item(
                'project-capability:runtime',
                'runtime_constraint',
                'PHP runtime constraint ' . $runtime,
                'The supported runtime constrains valid implementation syntax and dependencies.',
                'Read directly from composer.json require.php.',
                $authority,
                'implementation_constraint',
                'verified',
                true,
                $sourceRef,
                [],
            );
        }

        $scripts = is_array($payload['composer_scripts'] ?? null) ? $payload['composer_scripts'] : [];
        foreach ($scripts as $name => $definition) {
            if (!is_string($name) || trim($name) === '' || (!is_string($definition) && !is_array($definition))) {
                continue;
            }
            $name = trim($name);
            $items[] = $this->item(
                'project-capability:composer-script:' . $name,
                'repository_command',
                'composer ' . $name,
                'The repository declares this Composer script, so the command is an exact project-native entry point rather than an inferred tool invocation.',
                'Read directly from composer.json scripts.' . $name . '.',
                $authority,
                'verification_candidate',
                'verified',
                true,
                $sourceRef,
                [],
            );
        }

        $tools = is_array($payload['tool_packages'] ?? null) ? $payload['tool_packages'] : [];
        foreach ($tools as $name => $constraint) {
            if (!is_string($name) || !is_string($constraint)) {
                continue;
            }
            $items[] = $this->item(
                'project-capability:tool:' . $name,
                'tool_presence',
                $name . ' ' . $constraint,
                'Composer declares this known development tool package.',
                'Read from composer.json require/require-dev; package presence does not prove the repository-preferred invocation.',
                $authority,
                'capability_presence_only_do_not_infer_command',
                'verified',
                true,
                $sourceRef,
                [],
            );
        }

        foreach ([
            ['config_files', 'configuration_anchor', 'A supported project configuration file exists and may constrain the corresponding tool behavior.', 'Detected by the bounded project-capabilities provider allow-list.', 'configuration_anchor'],
            ['ci_workflows', 'ci_anchor', 'The CI workflow file exists, but this provider does not parse it into executable task policy.', 'Detected as a .github/workflows YAML file by the bounded project-capabilities provider.', 'navigation_anchor_only'],
        ] as [$payloadKey, $kind, $why, $how, $use]) {
            foreach ($this->strings($payload[$payloadKey] ?? []) as $path) {
                $items[] = $this->item(
                    'project-capability:' . $kind . ':' . $path,
                    $kind,
                    $path,
                    $why,
                    $how,
                    $authority,
                    $use,
                    'verified',
                    true,
                    $path,
                    [],
                );
            }
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $fact
     * @return ExplainItem
     */
    private function explainDocument(TaskBrief $task, array $fact): array
    {
        $payload = $this->payload($fact);
        $scope = $this->strings($fact['scope'] ?? []);
        $reasons = [];
        if ($scope === [] || array_intersect(['/', '*'], $scope) !== []) {
            $reasons[] = 'project-wide document policy';
        }
        foreach ($task->files as $file) {
            foreach ($scope as $candidate) {
                $prefix = rtrim($candidate, '/');
                if ($prefix !== '' && ($file === $prefix || str_starts_with($file, $prefix . '/'))) {
                    $reasons[] = 'scope overlap with ' . $file;
                }
            }
        }
        $matchingTags = array_values(array_intersect($this->strings($payload['tags'] ?? []), $task->tags));
        if ($matchingTags !== []) {
            $reasons[] = 'tag overlap: ' . implode(', ', $matchingTags);
        }
        $reasons = array_values(array_unique($reasons));

        $type = $this->string($fact['type'] ?? null) ?? 'document';
        $documentId = $this->string($payload['document_id'] ?? null) ?? $this->string($fact['id'] ?? null) ?? 'unknown';
        $sourceRef = $this->string($payload['canonical_source_ref'] ?? null) ?? $this->string($fact['source_ref'] ?? null);

        return $this->item(
            'project-document:' . $documentId,
            'project_document',
            $sourceRef ?? $documentId,
            $reasons === [] ? 'The document provider selected this source, but the exact relevance path cannot be reconstructed from the emitted fact.' : implode('; ', $reasons),
            'ScopedDocumentRecallProvider selection using path scope, task tags, or project-wide policy.',
            $this->string($fact['authority'] ?? null) ?? 'project_' . $type,
            $type === 'adr' ? 'architecture_constraint' : 'project_instruction',
            $reasons === [] ? 'unknown' : 'verified',
            true,
            $sourceRef,
            [],
        );
    }

    /**
     * @param array<string, mixed> $fact
     * @return list<ExplainItem>
     */
    private function explainOperatingPrompt(array $fact): array
    {
        $payload = $this->payload($fact);
        $promptId = $this->string($payload['prompt_id'] ?? null);
        if ($promptId === null) {
            return [];
        }
        $level = is_int($payload['level'] ?? null) ? $payload['level'] : null;
        $templateSha = $this->string($payload['template_sha256'] ?? null);

        return [$this->item(
            'operating-prompt:' . $promptId,
            'operating_prompt',
            $promptId . ($level === null ? '' : ' (L' . $level . ')'),
            'The task explicitly selected this reusable operating-prompt recipe.',
            'Resolved from the supplied manifest' . ($templateSha === null ? '.' : ' with template SHA-256 ' . $templateSha . '.'),
            $this->string($fact['authority'] ?? null) ?? 'task_input',
            $level === 2 ? 'construct_project_specific_l1_contract' : 'direct_l1_operating_contract',
            'verified',
            true,
            $this->string($fact['source_ref'] ?? null),
            [],
        )];
    }

    /** @return ExplainItem */
    private function explainGuidance(EvaluatedGuidance $evaluated): array
    {
        if ($evaluated->selected) {
            if ($evaluated->selectionReason === null) {
                throw new LogicException('Selected evaluated guidance requires a selection reason: ' . $evaluated->guidanceId);
            }

            return $this->item(
                'guidance:' . $evaluated->guidanceId,
                'guidance_decision',
                $evaluated->guidanceId,
                'RecallDecisionEngine selected this guidance: ' . $evaluated->selectionReason->value . '.',
                'Deterministic guidance eligibility and task-scope selection.',
                'guidance_selection',
                'active_guidance',
                'verified',
                true,
                $evaluated->sourceProposal,
                [],
            );
        }
        if ($evaluated->exclusionReason === null) {
            throw new LogicException('Excluded evaluated guidance requires an exclusion reason: ' . $evaluated->guidanceId);
        }

        return $this->item(
            'guidance:' . $evaluated->guidanceId,
            'guidance_decision',
            $evaluated->guidanceId,
            'RecallDecisionEngine evaluated this guidance for the current task.',
            'Deterministic guidance eligibility and task-scope selection.',
            'guidance_selection',
            'excluded_guidance_do_not_apply',
            'verified',
            false,
            $evaluated->sourceProposal,
            [],
            $evaluated->exclusionReason->value,
        );
    }

    /**
     * @param list<string> $evidenceIds
     * @param 'verified'|'inferred'|'unknown'|'blocked' $state
     * @return ExplainItem
     */
    private function item(
        string $id,
        string $kind,
        string $what,
        string $why,
        string $how,
        string $authority,
        string $use,
        string $state,
        bool $selected,
        ?string $sourceRef,
        array $evidenceIds,
        ?string $whyNot = null,
    ): array {
        $item = [
            'id' => $id,
            'kind' => $kind,
            'what' => $what,
            'why' => $why,
            'how' => $how,
            'authority' => $authority,
            'use' => $use,
            'state' => $state,
            'selected' => $selected,
            'source_ref' => $sourceRef,
            'evidence_ids' => $evidenceIds,
        ];
        if ($whyNot !== null && trim($whyNot) !== '') {
            $item['why_not'] = trim($whyNot);
        }

        return $item;
    }

    /**
     * @param array<string, mixed> $fact
     * @return array<string, mixed>
     */
    private function payload(array $fact): array
    {
        return is_array($fact['payload'] ?? null) ? $fact['payload'] : [];
    }

    /** @return list<array<string, mixed>> */
    private function arrays(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_array'));
    }

    /** @return list<string> */
    private function strings(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $values = array_values(array_filter(
            array_map(
                static fn (mixed $item): ?string => is_string($item) && trim($item) !== '' ? trim($item) : null,
                $value,
            ),
            static fn (?string $item): bool => $item !== null,
        ));

        return array_values(array_unique($values));
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
