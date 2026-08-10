<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Context;

use voku\AgentRecallCompiler\EvaluatedGuidance;
use voku\AgentRecallCompiler\RecallResult;
use voku\AgentRecallCompiler\TaskBrief;

/**
 * Projects already-compiled evidence into an explain plan for receiving agents.
 *
 * This class never invents implementation rationale. It only explains why Recall
 * selected or rejected context using provenance that is already present in the
 * compiled facts and deterministic selection result.
 *
 * @phpstan-type ExplainItem array{
 *     id: string,
 *     kind: string,
 *     what: string,
 *     why: string,
 *     how: string,
 *     authority: string,
 *     use: string,
 *     state: 'verified'|'inferred'|'unknown'|'blocked',
 *     selected: bool,
 *     source_ref: string|null,
 *     evidence_ids: list<string>,
 *     why_not?: string
 * }
 */
final readonly class ContextExplainProjector
{
    /**
     * @param list<array<string, mixed>> $facts
     * @return list<ExplainItem>
     */
    public function project(TaskBrief $task, array $facts, RecallResult $result): array
    {
        /** @var array<string, ExplainItem> $items */
        $items = [];

        foreach ($facts as $fact) {
            $type = $fact['type'] ?? null;
            if (!is_string($type)) {
                continue;
            }

            match ($type) {
                'edit_context' => $this->appendEditContext($items, $fact),
                'project_capabilities' => $this->appendProjectCapabilities($items, $fact),
                'adr', 'skill' => $this->appendProjectDocument($items, $fact, $task),
                'operating_prompt' => $this->appendOperatingPrompt($items, $fact),
                'navigation_status' => $this->appendNavigationStatus($items, $fact),
                'navigation_candidates' => $this->appendNavigationCandidates($items, $fact),
                default => null,
            };
        }

        foreach ($result->evaluatedGuidance as $evaluated) {
            $this->appendGuidanceDecision($items, $evaluated);
        }

        ksort($items, SORT_STRING);

        return array_values($items);
    }

    /**
     * @param array<string, ExplainItem> $items
     * @param array<string, mixed> $fact
     */
    private function appendEditContext(array &$items, array $fact): void
    {
        $payload = $this->payload($fact);
        $sourceRef = $this->string($fact['source_ref'] ?? null);
        $authority = $this->string($fact['authority'] ?? null) ?? 'derived_navigation';
        $slices = $payload['slices'] ?? [];
        if (is_array($slices)) {
            foreach ($slices as $index => $slice) {
                if (!is_array($slice)) {
                    continue;
                }
                $path = $this->string($slice['path'] ?? null);
                if ($path === null) {
                    continue;
                }
                $roles = $this->strings($slice['roles'] ?? []);
                $reasons = $this->strings($slice['reasons'] ?? []);
                $evidenceIds = $this->strings($slice['evidence_ids'] ?? []);
                $lineStart = is_int($slice['line_start'] ?? null) ? $slice['line_start'] : null;
                $lineEnd = is_int($slice['line_end'] ?? null) ? $slice['line_end'] : null;
                $what = $path;
                if ($lineStart !== null && $lineEnd !== null) {
                    $what .= ':' . $lineStart . '-' . $lineEnd;
                }

                $knownRoles = ['primary', 'contract', 'change_candidate', 'verification', 'dependency', 'type_definition'];
                $unknownRoles = array_values(array_diff($roles, $knownRoles));
                $state = $roles === [] || $unknownRoles !== [] ? 'unknown' : 'verified';
                $use = $this->editUse($roles);
                $why = $reasons === []
                    ? ($roles === []
                        ? 'agent-map selected this source slice but exposed no recognized role or reason.'
                        : 'agent-map selected this source slice as ' . implode(', ', $roles) . ' context for the current target.')
                    : implode('; ', $reasons);
                $how = 'agent-map EditContextPlan';
                if ($roles !== []) {
                    $how .= ' role(s): ' . implode(', ', $roles);
                }

                $id = 'map-slice:' . hash('sha256', $what . "\0" . implode(',', $roles) . "\0" . (string) $index);
                $items[$id] = $this->item(
                    id: $id,
                    kind: 'map_slice',
                    what: $what,
                    why: $why,
                    how: $how,
                    authority: $authority,
                    use: $use,
                    state: $state,
                    selected: true,
                    sourceRef: $sourceRef,
                    evidenceIds: $evidenceIds,
                );
            }
        }

        $blindSpots = $payload['blind_spots'] ?? [];
        if (is_array($blindSpots)) {
            foreach ($blindSpots as $index => $blindSpot) {
                if (!is_array($blindSpot)) {
                    continue;
                }
                $kind = $this->string($blindSpot['kind'] ?? null) ?? 'unknown';
                $message = $this->string($blindSpot['message'] ?? null) ?? 'agent-map reported an unresolved blind spot.';
                $path = $this->string($blindSpot['path'] ?? null);
                $line = is_int($blindSpot['line'] ?? null) ? $blindSpot['line'] : null;
                $what = $path ?? ('blind-spot:' . $kind);
                if ($path !== null && $line !== null) {
                    $what .= ':' . $line;
                }
                $id = 'map-blind-spot:' . hash('sha256', $kind . "\0" . $what . "\0" . (string) $index);
                $items[$id] = $this->item(
                    id: $id,
                    kind: 'map_blind_spot',
                    what: $what,
                    why: $message,
                    how: 'agent-map reported a static-analysis blind spot while constructing EditContextPlan.',
                    authority: $authority,
                    use: 'investigate_before_claiming_complete',
                    state: 'unknown',
                    selected: true,
                    sourceRef: $sourceRef,
                    evidenceIds: $this->strings($blindSpot['evidence_ids'] ?? []),
                );
            }
        }

        $omitted = $payload['omitted'] ?? [];
        if (is_array($omitted)) {
            foreach ($omitted as $index => $candidate) {
                if (!is_array($candidate)) {
                    continue;
                }
                $symbol = $this->string($candidate['symbol_id'] ?? null) ?? 'unknown';
                $role = $this->string($candidate['role'] ?? null) ?? 'unknown';
                $reason = $this->string($candidate['reason'] ?? null) ?? 'agent-map omitted this candidate from the bounded context.';
                $id = 'map-omitted:' . hash('sha256', $symbol . "\0" . $role . "\0" . (string) $index);
                $items[$id] = $this->item(
                    id: $id,
                    kind: 'map_omission',
                    what: $symbol,
                    why: 'The candidate was considered while constructing bounded edit context.',
                    how: 'agent-map EditContextPlan omission for role ' . $role . '.',
                    authority: $authority,
                    use: 'investigate_if_relevant',
                    state: 'unknown',
                    selected: false,
                    sourceRef: $sourceRef,
                    evidenceIds: [],
                    whyNot: $reason,
                );
            }
        }
    }

    /** @param list<string> $roles */
    private function editUse(array $roles): string
    {
        if (in_array('primary', $roles, true)) {
            return 'implementation_candidate';
        }
        if (in_array('contract', $roles, true)) {
            return 'compatibility_contract';
        }
        if (in_array('change_candidate', $roles, true)) {
            return 'inspect_and_edit_if_required';
        }
        if (in_array('verification', $roles, true)) {
            return 'verification';
        }
        if (array_intersect(['dependency', 'type_definition'], $roles) !== []) {
            return 'context_only_do_not_edit_from_selection_alone';
        }

        return 'context_only_until_verified';
    }

    /**
     * @param array<string, ExplainItem> $items
     * @param array<string, mixed> $fact
     */
    private function appendProjectCapabilities(array &$items, array $fact): void
    {
        $payload = $this->payload($fact);
        $authority = $this->string($fact['authority'] ?? null) ?? 'project_metadata';
        $sourceRef = $this->string($fact['source_ref'] ?? null);

        $runtime = $this->string($payload['runtime_constraint'] ?? null);
        if ($runtime !== null) {
            $id = 'project-capability:runtime';
            $items[$id] = $this->item(
                id: $id,
                kind: 'runtime_constraint',
                what: 'PHP runtime constraint ' . $runtime,
                why: 'The supported runtime constrains valid implementation syntax and dependencies.',
                how: 'Read directly from composer.json require.php.',
                authority: $authority,
                use: 'implementation_constraint',
                state: 'verified',
                selected: true,
                sourceRef: $sourceRef,
                evidenceIds: [],
            );
        }

        $scripts = $payload['composer_scripts'] ?? [];
        if (is_array($scripts)) {
            foreach ($scripts as $name => $definition) {
                if (!is_string($name) || trim($name) === '' || (!is_string($definition) && !is_array($definition))) {
                    continue;
                }
                $command = 'composer ' . trim($name);
                $id = 'project-capability:composer-script:' . trim($name);
                $items[$id] = $this->item(
                    id: $id,
                    kind: 'repository_command',
                    what: $command,
                    why: 'The repository declares this Composer script, so the command is an exact project-native entry point rather than an inferred tool invocation.',
                    how: 'Read directly from composer.json scripts.' . trim($name) . '.',
                    authority: $authority,
                    use: 'verification_candidate',
                    state: 'verified',
                    selected: true,
                    sourceRef: $sourceRef,
                    evidenceIds: [],
                );
            }
        }

        $toolPackages = $payload['tool_packages'] ?? [];
        if (is_array($toolPackages)) {
            foreach ($toolPackages as $name => $constraint) {
                if (!is_string($name) || !is_string($constraint)) {
                    continue;
                }
                $id = 'project-capability:tool:' . $name;
                $items[$id] = $this->item(
                    id: $id,
                    kind: 'tool_presence',
                    what: $name . ' ' . $constraint,
                    why: 'Composer declares this known development tool package.',
                    how: 'Read from composer.json require/require-dev; package presence does not prove the repository-preferred invocation.',
                    authority: $authority,
                    use: 'capability_presence_only_do_not_infer_command',
                    state: 'verified',
                    selected: true,
                    sourceRef: $sourceRef,
                    evidenceIds: [],
                );
            }
        }

        foreach ($this->strings($payload['config_files'] ?? []) as $configFile) {
            $id = 'project-capability:config:' . $configFile;
            $items[$id] = $this->item(
                id: $id,
                kind: 'configuration_anchor',
                what: $configFile,
                why: 'A supported project configuration file exists and may constrain the corresponding tool behavior.',
                how: 'Detected by the bounded project-capabilities provider allow-list.',
                authority: $authority,
                use: 'configuration_anchor',
                state: 'verified',
                selected: true,
                sourceRef: $configFile,
                evidenceIds: [],
            );
        }

        foreach ($this->strings($payload['ci_workflows'] ?? []) as $workflow) {
            $id = 'project-capability:ci:' . $workflow;
            $items[$id] = $this->item(
                id: $id,
                kind: 'ci_anchor',
                what: $workflow,
                why: 'The CI workflow file exists, but this provider does not parse it into executable task policy.',
                how: 'Detected as a .github/workflows YAML file by the bounded project-capabilities provider.',
                authority: $authority,
                use: 'navigation_anchor_only',
                state: 'verified',
                selected: true,
                sourceRef: $workflow,
                evidenceIds: [],
            );
        }
    }

    /**
     * @param array<string, ExplainItem> $items
     * @param array<string, mixed> $fact
     */
    private function appendProjectDocument(array &$items, array $fact, TaskBrief $task): void
    {
        $payload = $this->payload($fact);
        $documentId = $this->string($payload['document_id'] ?? null) ?? $this->string($fact['id'] ?? null) ?? 'unknown';
        $scope = $this->strings($fact['scope'] ?? []);
        $tags = $this->strings($payload['tags'] ?? []);
        $reasons = [];

        if ($scope === [] || in_array('/', $scope, true) || in_array('*', $scope, true)) {
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
        $matchingTags = array_values(array_intersect($tags, $task->tags));
        if ($matchingTags !== []) {
            $reasons[] = 'tag overlap: ' . implode(', ', $matchingTags);
        }
        $reasons = array_values(array_unique($reasons));

        $sourceRef = $this->string($payload['canonical_source_ref'] ?? null) ?? $this->string($fact['source_ref'] ?? null);
        $type = $this->string($fact['type'] ?? null) ?? 'document';
        $id = 'project-document:' . $documentId;
        $items[$id] = $this->item(
            id: $id,
            kind: 'project_document',
            what: $sourceRef ?? $documentId,
            why: $reasons === [] ? 'The document provider selected this source, but the exact relevance path cannot be reconstructed from the emitted fact.' : implode('; ', $reasons),
            how: 'ScopedDocumentRecallProvider selection using path scope, task tags, or project-wide policy.',
            authority: $this->string($fact['authority'] ?? null) ?? ('project_' . $type),
            use: $type === 'adr' ? 'architecture_constraint' : 'project_instruction',
            state: $reasons === [] ? 'unknown' : 'verified',
            selected: true,
            sourceRef: $sourceRef,
            evidenceIds: [],
        );
    }

    /**
     * @param array<string, ExplainItem> $items
     * @param array<string, mixed> $fact
     */
    private function appendOperatingPrompt(array &$items, array $fact): void
    {
        $payload = $this->payload($fact);
        $promptId = $this->string($payload['prompt_id'] ?? null);
        if ($promptId === null) {
            return;
        }
        $level = is_int($payload['level'] ?? null) ? $payload['level'] : null;
        $templateSha = $this->string($payload['template_sha256'] ?? null);
        $sourceRef = $this->string($fact['source_ref'] ?? null);
        $id = 'operating-prompt:' . $promptId;
        $items[$id] = $this->item(
            id: $id,
            kind: 'operating_prompt',
            what: $promptId . ($level === null ? '' : ' (L' . $level . ')'),
            why: 'The task explicitly selected this reusable operating-prompt recipe.',
            how: 'Resolved from the supplied manifest' . ($templateSha === null ? '.' : ' with template SHA-256 ' . $templateSha . '.'),
            authority: $this->string($fact['authority'] ?? null) ?? 'task_input',
            use: $level === 2 ? 'construct_project_specific_l1_contract' : 'direct_l1_operating_contract',
            state: 'verified',
            selected: true,
            sourceRef: $sourceRef,
            evidenceIds: [],
        );
    }

    /**
     * @param array<string, ExplainItem> $items
     * @param array<string, mixed> $fact
     */
    private function appendNavigationStatus(array &$items, array $fact): void
    {
        $payload = $this->payload($fact);
        $path = $this->string($payload['path'] ?? null) ?? 'unknown';
        $status = $this->string($payload['status'] ?? null) ?? 'unknown';
        $reason = $this->string($payload['reason'] ?? null);
        $id = 'navigation-status:' . $path;
        $items[$id] = $this->item(
            id: $id,
            kind: 'navigation_status',
            what: $path,
            why: 'The requested file could not be promoted to current source-backed navigation context.',
            how: 'agent-map file lookup returned status ' . $status . '.',
            authority: $this->string($fact['authority'] ?? null) ?? 'derived_navigation',
            use: 'resolve_before_relying_on_navigation',
            state: $status === 'stale' ? 'blocked' : 'unknown',
            selected: false,
            sourceRef: $this->string($fact['source_ref'] ?? null),
            evidenceIds: [],
            whyNot: $reason ?? $status,
        );
    }

    /**
     * @param array<string, ExplainItem> $items
     * @param array<string, mixed> $fact
     */
    private function appendNavigationCandidates(array &$items, array $fact): void
    {
        $payload = $this->payload($fact);
        $status = $this->string($payload['status'] ?? null) ?? 'unknown';
        $sourceRef = $this->string($fact['source_ref'] ?? null);
        if ($status !== 'ranked') {
            $reason = $this->string($payload['reason'] ?? null) ?? 'No ranked navigation candidates were produced.';
            $id = 'navigation-candidates:status';
            $items[$id] = $this->item(
                id: $id,
                kind: 'navigation_candidates',
                what: 'ranked navigation candidates',
                why: 'Hybrid search was considered as an optional navigation aid.',
                how: 'agent-map search-index status: ' . $status . '.',
                authority: $this->string($fact['authority'] ?? null) ?? 'derived_navigation',
                use: 'navigation_leads_only',
                state: 'unknown',
                selected: false,
                sourceRef: $sourceRef,
                evidenceIds: [],
                whyNot: $reason,
            );

            return;
        }

        $results = $payload['results'] ?? [];
        if (!is_array($results)) {
            return;
        }
        foreach ($results as $index => $hit) {
            if (!is_array($hit)) {
                continue;
            }
            $path = $this->string($hit['file_path'] ?? null) ?? 'unknown';
            $start = is_int($hit['start_line'] ?? null) ? $hit['start_line'] : null;
            $end = is_int($hit['end_line'] ?? null) ? $hit['end_line'] : null;
            $what = $path;
            if ($start !== null && $end !== null) {
                $what .= ':' . $start . '-' . $end;
            }
            $reasons = $this->strings($hit['reasons'] ?? []);
            $id = 'navigation-candidate:' . hash('sha256', $what . "\0" . (string) $index);
            $items[$id] = $this->item(
                id: $id,
                kind: 'navigation_candidate',
                what: $what,
                why: $reasons === [] ? 'Derived search ranked this source as a possible navigation lead.' : implode('; ', $reasons),
                how: 'agent-map derived hybrid-search ranking; ranking is not resolved source evidence.',
                authority: $this->string($fact['authority'] ?? null) ?? 'derived_navigation',
                use: 'open_and_verify_before_using',
                state: 'inferred',
                selected: true,
                sourceRef: $sourceRef,
                evidenceIds: [],
            );
        }
    }

    /** @param array<string, ExplainItem> $items */
    private function appendGuidanceDecision(array &$items, EvaluatedGuidance $evaluated): void
    {
        $id = 'guidance:' . $evaluated->guidanceId;
        if ($evaluated->selected) {
            $reason = $evaluated->selectionReason->value;
            $items[$id] = $this->item(
                id: $id,
                kind: 'guidance_decision',
                what: $evaluated->guidanceId,
                why: 'RecallDecisionEngine selected this guidance: ' . $reason . '.',
                how: 'Deterministic guidance eligibility and task-scope selection.',
                authority: 'guidance_selection',
                use: 'active_guidance',
                state: 'verified',
                selected: true,
                sourceRef: $evaluated->sourceProposal,
                evidenceIds: [],
            );

            return;
        }

        $reason = $evaluated->exclusionReason->value;
        $items[$id] = $this->item(
            id: $id,
            kind: 'guidance_decision',
            what: $evaluated->guidanceId,
            why: 'RecallDecisionEngine evaluated this guidance for the current task.',
            how: 'Deterministic guidance eligibility and task-scope selection.',
            authority: 'guidance_selection',
            use: 'excluded_guidance_do_not_apply',
            state: 'verified',
            selected: false,
            sourceRef: $evaluated->sourceProposal,
            evidenceIds: [],
            whyNot: $reason,
        );
    }

    /** @param array<string, mixed> $fact
     * @return array<string, mixed>
     */
    private function payload(array $fact): array
    {
        return is_array($fact['payload'] ?? null) ? $fact['payload'] : [];
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** @return list<string> */
    private function strings(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $strings = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $strings[] = trim($item);
            }
        }

        return array_values(array_unique($strings));
    }

    /**
     * @param 'verified'|'inferred'|'unknown'|'blocked' $state
     * @param list<string> $evidenceIds
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
}
