<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Output;

use JsonException;
use RuntimeException;
use voku\AgentRecallCompiler\CanonicalJson;
use voku\AgentRecallCompiler\ExclusionReason;
use voku\AgentRecallCompiler\GuidanceType;
use voku\AgentRecallCompiler\SelectionReason;

/**
 * Reads the exact persisted context-selection explanation without recompiling Recall.
 */
final readonly class CompiledContextExplanationReader
{
    public function readForTask(string $recallRoot, string $taskId): ?CompiledContextExplanation
    {
        $root = rtrim($recallRoot, '/\\');
        $outputReader = new CompiledRecallOutputReader();

        $canonical = $outputReader->read($root . '/' . $taskId);
        if ($canonical !== null) {
            if (!$canonical->describesTask($taskId)) {
                throw new RuntimeException('Canonical Recall output does not describe requested task: ' . $taskId);
            }

            return $this->read($root . '/' . $taskId);
        }

        $current = $outputReader->read($root . '/current');
        if ($current !== null && $current->describesTask($taskId)) {
            return $this->read($root . '/current');
        }

        return null;
    }

    public function read(string $outputDirectory): ?CompiledContextExplanation
    {
        $directory = rtrim($outputDirectory, '/\\');
        $output = (new CompiledRecallOutputReader())->read($directory);
        if ($output === null) {
            return null;
        }

        $path = $directory . '/selection-report.json';
        if (!is_file($path)) {
            throw new RuntimeException('Compiled context explanation is unavailable: selection-report.json is missing. Recompile Recall.');
        }

        $document = $this->decode($path);
        if (($document['schema_version'] ?? null) !== '1.0') {
            throw new RuntimeException('Unsupported selection-report schema version in ' . $path);
        }

        $bundleSha256 = $this->requiredString($document['bundle_sha256'] ?? null, 'bundle_sha256');
        $metaBundleSha256 = $output->bundleSha256();
        if ($metaBundleSha256 === null || !hash_equals($metaBundleSha256, $bundleSha256)) {
            throw new RuntimeException('selection-report.json is bound to a different Recall bundle.');
        }

        return new CompiledContextExplanation(
            selectionReportPath: $path,
            compilationId: $output->compilationId(),
            bundleSha256: $bundleSha256,
            constraints: $this->constraints($document['selected_constraints'] ?? []),
            guidance: $this->guidance($document['evaluated_guidance'] ?? []),
            items: $this->items($document['context_explain'] ?? []),
            warnings: $this->stringList($document['warnings'] ?? [], 'warnings'),
            outcomeStats: $this->bundleOutcomeStats($directory, $bundleSha256),
            integrityFailures: $output->integrityFailures(),
        );
    }

    /** @return list<CompiledConstraintSelection> */
    private function constraints(mixed $value): array
    {
        if (!is_array($value)) {
            throw new RuntimeException('selection-report selected_constraints must be an array.');
        }

        $constraints = [];
        foreach ($value as $index => $item) {
            if (!is_array($item)) {
                throw new RuntimeException('selection-report selected_constraints[' . $index . '] must be an object.');
            }

            $constraints[] = new CompiledConstraintSelection(
                id: $this->requiredString($item['id'] ?? null, 'selected_constraints.id'),
                engine: $this->requiredString($item['engine'] ?? null, 'selected_constraints.engine'),
                ruleIdentifier: $this->requiredString($item['rule_identifier'] ?? null, 'selected_constraints.rule_identifier'),
                sourceProposal: $this->requiredString($item['source_proposal'] ?? null, 'selected_constraints.source_proposal'),
                scope: array_key_exists('scope', $item)
                    ? $this->stringList($item['scope'], 'selected_constraints.scope')
                    : null,
                validationCommands: array_key_exists('validation_commands', $item)
                    ? $this->stringList($item['validation_commands'], 'selected_constraints.validation_commands')
                    : null,
                status: array_key_exists('status', $item)
                    ? $this->requiredString($item['status'], 'selected_constraints.status')
                    : null,
                tags: array_key_exists('tags', $item)
                    ? $this->stringList($item['tags'], 'selected_constraints.tags')
                    : null,
            );
        }

        return $constraints;
    }

    /** @return list<CompiledGuidanceDecision> */
    private function guidance(mixed $value): array
    {
        if (!is_array($value)) {
            throw new RuntimeException('selection-report evaluated_guidance must be an array.');
        }

        $guidance = [];
        foreach ($value as $index => $item) {
            if (!is_array($item)) {
                throw new RuntimeException('selection-report evaluated_guidance[' . $index . '] must be an object.');
            }

            $typeValue = $this->requiredString($item['guidance_type'] ?? null, 'evaluated_guidance.guidance_type');
            $type = GuidanceType::tryFrom($typeValue);
            if ($type === null) {
                throw new RuntimeException('Unknown persisted guidance type: ' . $typeValue);
            }
            $eligible = $item['eligible'] ?? null;
            $selected = $item['selected'] ?? null;
            if (!is_bool($eligible) || !is_bool($selected)) {
                throw new RuntimeException('Persisted guidance eligibility/selection flags must be booleans.');
            }

            $selectionReason = $this->selectionReason($item['selection_reason'] ?? null);
            $exclusionReason = $this->exclusionReason($item['exclusion_reason'] ?? null);
            if ($selected && $selectionReason === null) {
                throw new RuntimeException('Selected persisted guidance requires a selection reason.');
            }
            if (!$selected && $exclusionReason === null) {
                throw new RuntimeException('Excluded persisted guidance requires an exclusion reason.');
            }

            $guidance[] = new CompiledGuidanceDecision(
                guidanceId: $this->requiredString($item['guidance_id'] ?? null, 'evaluated_guidance.guidance_id'),
                guidanceType: $type,
                eligible: $eligible,
                selected: $selected,
                selectionReason: $selectionReason,
                exclusionReason: $exclusionReason,
                taskFiles: $this->stringList($item['task_files'] ?? [], 'evaluated_guidance.task_files'),
                sourceProposal: $this->optionalString($item['source_proposal'] ?? null, 'evaluated_guidance.source_proposal'),
            );
        }

        return $guidance;
    }

    /** @return list<CompiledContextExplainItem> */
    private function items(mixed $value): array
    {
        if (!is_array($value)) {
            throw new RuntimeException('selection-report context_explain must be an array.');
        }

        $items = [];
        foreach ($value as $index => $item) {
            if (!is_array($item)) {
                throw new RuntimeException('selection-report context_explain[' . $index . '] must be an object.');
            }

            $stateValue = $this->requiredString($item['state'] ?? null, 'context_explain.state');
            $state = ContextExplainState::tryFrom($stateValue);
            if ($state === null) {
                throw new RuntimeException('Unknown persisted context explanation state: ' . $stateValue);
            }
            $selected = $item['selected'] ?? null;
            if (!is_bool($selected)) {
                throw new RuntimeException('Persisted context explanation selected flag must be boolean.');
            }

            $items[] = new CompiledContextExplainItem(
                id: $this->requiredString($item['id'] ?? null, 'context_explain.id'),
                kind: $this->requiredString($item['kind'] ?? null, 'context_explain.kind'),
                what: $this->requiredString($item['what'] ?? null, 'context_explain.what'),
                why: $this->requiredString($item['why'] ?? null, 'context_explain.why'),
                how: $this->requiredString($item['how'] ?? null, 'context_explain.how'),
                authority: $this->requiredString($item['authority'] ?? null, 'context_explain.authority'),
                use: $this->requiredString($item['use'] ?? null, 'context_explain.use'),
                state: $state,
                selected: $selected,
                sourceRef: $this->optionalString($item['source_ref'] ?? null, 'context_explain.source_ref'),
                evidenceIds: $this->stringList($item['evidence_ids'] ?? [], 'context_explain.evidence_ids'),
                whyNot: $this->optionalString($item['why_not'] ?? null, 'context_explain.why_not'),
            );
        }

        return $items;
    }

    /**
     * @return array<string, array{selected_count:int, helpful_count:int, irrelevant_count:int, harmful_count:int, violation_detected_count:int}>
     */
    private function bundleOutcomeStats(string $directory, string $expectedBundleSha256): array
    {
        $path = $directory . '/recall.bundle.json';
        if (!is_file($path)) {
            throw new RuntimeException('Compiled context outcome statistics are unavailable: recall.bundle.json is missing.');
        }

        $bundle = $this->decode($path);
        if (!hash_equals($expectedBundleSha256, CanonicalJson::digest($bundle))) {
            throw new RuntimeException('recall.bundle.json does not match the context explanation bundle identity.');
        }

        return $this->outcomeStats($bundle['outcome_stats'] ?? []);
    }

    /**
     * @return array<string, array{selected_count:int, helpful_count:int, irrelevant_count:int, harmful_count:int, violation_detected_count:int}>
     */
    private function outcomeStats(mixed $value): array
    {
        if (!is_array($value)) {
            throw new RuntimeException('Persisted outcome_stats must be an object.');
        }

        $result = [];
        foreach ($value as $guidanceId => $stats) {
            if (!is_string($guidanceId) || $guidanceId === '' || !is_array($stats)) {
                throw new RuntimeException('Persisted outcome_stats contains an invalid guidance entry.');
            }

            $result[$guidanceId] = [
                'selected_count' => $this->outcomeCount($stats, $guidanceId, 'selected_count'),
                'helpful_count' => $this->outcomeCount($stats, $guidanceId, 'helpful_count'),
                'irrelevant_count' => $this->outcomeCount($stats, $guidanceId, 'irrelevant_count'),
                'harmful_count' => $this->outcomeCount($stats, $guidanceId, 'harmful_count'),
                'violation_detected_count' => $this->outcomeCount($stats, $guidanceId, 'violation_detected_count'),
            ];
        }

        return $result;
    }

    /** @param array<mixed> $stats */
    private function outcomeCount(array $stats, string $guidanceId, string $key): int
    {
        $count = $stats[$key] ?? null;
        if (!is_int($count) || $count < 0) {
            throw new RuntimeException('Persisted outcome_stats.' . $guidanceId . '.' . $key . ' must be a non-negative integer.');
        }

        return $count;
    }

    private function selectionReason(mixed $value): ?SelectionReason
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || ($reason = SelectionReason::tryFrom($value)) === null) {
            throw new RuntimeException('Unknown persisted guidance selection reason.');
        }

        return $reason;
    }

    private function exclusionReason(mixed $value): ?ExclusionReason
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || ($reason = ExclusionReason::tryFrom($value)) === null) {
            throw new RuntimeException('Unknown persisted guidance exclusion reason.');
        }

        return $reason;
    }

    /** @return list<string> */
    private function stringList(mixed $value, string $field): array
    {
        if (!is_array($value)) {
            throw new RuntimeException('Persisted ' . $field . ' must be an array.');
        }

        $strings = [];
        foreach ($value as $item) {
            if (!is_string($item) || $item === '') {
                throw new RuntimeException('Persisted ' . $field . ' must contain only non-empty strings.');
            }
            $strings[] = $item;
        }

        return $strings;
    }

    private function requiredString(mixed $value, string $field): string
    {
        if (!is_string($value) || $value === '') {
            throw new RuntimeException('Persisted ' . $field . ' must be a non-empty string.');
        }

        return $value;
    }

    private function optionalString(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || $value === '') {
            throw new RuntimeException('Persisted ' . $field . ' must be null or a non-empty string.');
        }

        return $value;
    }

    /** @return array<string, mixed> */
    private function decode(string $path): array
    {
        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new RuntimeException('Unable to read persisted context explanation: ' . $path);
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Invalid persisted context explanation JSON: ' . $exception->getMessage(), 0, $exception);
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException('Persisted context explanation must decode to a JSON object.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
