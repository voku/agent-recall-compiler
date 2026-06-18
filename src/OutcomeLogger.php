<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler;

use DateTimeImmutable;
use DateTimeInterface;
use RuntimeException;

final class OutcomeLogger
{
    private readonly RecallRepository $repository;
    private readonly EventHistoryWriter $eventHistoryWriter;

    public function __construct()
    {
        $this->repository = new RecallRepository();
        $this->eventHistoryWriter = new EventHistoryWriter();
    }

    /**
     * Log a finalized outcome from draft file.
     */
    public function log(string $root, string $draftPath, string $actor, string $commit): string
    {
        if (!is_file($draftPath)) {
            throw new RuntimeException('outcome draft file not found: ' . $draftPath);
        }

        $content = file_get_contents($draftPath);
        if ($content === false) {
            throw new RuntimeException('cannot read outcome draft file: ' . $draftPath);
        }

        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RuntimeException('malformed JSON in outcome draft: ' . $e->getMessage());
        }

        if (!is_array($data)) {
            throw new RuntimeException('outcome draft must be a JSON object');
        }

        if (isset($data['schema_version']) && $data['schema_version'] !== '1.0') {
            throw new RuntimeException('unsupported outcome draft schema version: ' . $data['schema_version']);
        }

        if (isset($data['compilation_id']) || isset($data['guidance_outcomes']) || isset($data['evaluated_guidance'])) {
            return $this->logEventDraft($root, $draftPath, $data, $actor, $commit);
        }

        // Validate required fields
        $taskId = $data['task_id'] ?? null;
        if (!is_string($taskId) || trim($taskId) === '') {
            throw new RuntimeException('outcome draft requires non-empty string: task_id');
        }

        // Load active proposals to validate references
        $activeGuidance = $this->repository->loadActiveGuidance($root);
        $validIds = array_map(static fn(RecallGuidance $g) => $g->id, $activeGuidance);

        // Also check rejected proposals as valid references
        $rejectedGuidance = $this->repository->loadRejectedGuidance($root);
        foreach ($rejectedGuidance as $rg) {
            $validIds[] = $rg->id;
        }
        foreach ($this->repository->loadConstraintManifests($root) as $constraint) {
            $validIds[] = $constraint->id;
            $validIds[] = $constraint->sourceProposal;
        }

        $guidanceUsed = $data['guidance_used'] ?? [];
        $constraintsUsed = $data['constraints_used'] ?? [];
        $appliedProposals = $data['applied_proposals'] ?? [];
        $selected = $data['selected'] ?? array_values(array_unique(array_merge($guidanceUsed, $constraintsUsed)));
        $helpful = $data['helpful'] ?? [];
        $irrelevant = $data['irrelevant'] ?? [];
        $harmful = $data['harmful'] ?? [];

        $allRefs = array_unique(array_merge($guidanceUsed, $constraintsUsed, $appliedProposals, $selected, $helpful, $irrelevant, $harmful));
        foreach ($allRefs as $ref) {
            if (!is_string($ref) || trim($ref) === '') {
                throw new RuntimeException('invalid or empty guidance reference in outcome list');
            }
            if (!in_array($ref, $validIds, true)) {
                throw new RuntimeException(sprintf("referenced guidance '%s' does not exist in learning repository", $ref));
            }
        }
        $this->assertSelectedFeedbackIsExplicit($selected, $helpful, $irrelevant, $harmful);

        $result = $data['result'] ?? 'successful';
        $allowedResults = [
            'successful',
            'partially_successful',
            'failed',
            'unknown',
            'violation_detected',
            'false_positive',
            'rule_bypassed',
            'rule_suppressed',
            'rule_disabled',
            'no_violation_observed',
        ];
        if (!is_string($result) || !in_array($result, $allowedResults, true)) {
            throw new RuntimeException('unsupported outcome result value in draft');
        }

        // Generate sequential outcome ID
        $now = new DateTimeImmutable('now');
        $dateStr = $now->format('Y-m-d');
        $prefix = 'outcome.' . $dateStr . '.';

        $allOutcomes = $this->repository->loadOutcomes($root);
        $maxNum = 0;
        foreach ($allOutcomes as $existing) {
            $id = $existing['id'] ?? '';
            if (str_starts_with($id, $prefix)) {
                $suffix = substr($id, strlen($prefix));
                if (is_numeric($suffix)) {
                    $maxNum = max($maxNum, (int)$suffix);
                }
            }
        }
        $outcomeId = $prefix . sprintf('%03d', $maxNum + 1);

        // Format outcome line
        $outcomeRecord = [
            'schema_version' => '1.0',
            'id' => $outcomeId,
            'task_id' => $taskId,
            'session' => $data['session'] ?? 'sess_none',
            'created_at' => $now->format(DateTimeInterface::ATOM),
            'guidance_used' => array_values(array_filter($guidanceUsed, 'is_string')),
            'constraints_used' => array_values(array_filter($constraintsUsed, 'is_string')),
            'applied_proposals' => array_values(array_filter($appliedProposals, 'is_string')),
            'selected' => array_values(array_filter($selected, 'is_string')),
            'applied' => array_values(array_filter($data['applied'] ?? $appliedProposals, 'is_string')),
            'helpful' => array_values(array_filter($helpful, 'is_string')),
            'irrelevant' => array_values(array_filter($irrelevant, 'is_string')),
            'harmful' => array_values(array_filter($harmful, 'is_string')),
            'result' => $result,
            'comment' => is_string($data['comment'] ?? null) ? trim($data['comment']) : '',
            'actor' => $actor,
            'commit' => $commit,
        ];

        $outcomeLine = json_encode($outcomeRecord, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";

        $historyDir = $root . '/history';
        if (!is_dir($historyDir)) {
            if (!mkdir($historyDir, 0777, true) && !is_dir($historyDir)) {
                throw new \RuntimeException(sprintf('Directory "%s" was not created', $historyDir));
            }
        }
        $outcomesPath = $historyDir . '/outcomes.jsonl';
        
        $written = file_put_contents($outcomesPath, $outcomeLine, FILE_APPEND);
        if ($written === false) {
            throw new RuntimeException('failed to write outcome record to outcomes.jsonl');
        }

        return $outcomeId;
    }

    /**
     * @param mixed $selected
     * @param mixed $helpful
     * @param mixed $irrelevant
     * @param mixed $harmful
     */
    private function assertSelectedFeedbackIsExplicit(mixed $selected, mixed $helpful, mixed $irrelevant, mixed $harmful): void
    {
        $selectedList = $this->stringList($selected);
        if ($selectedList === []) {
            return;
        }

        $helpfulList = $this->stringList($helpful);
        $irrelevantList = $this->stringList($irrelevant);
        $harmfulList = $this->stringList($harmful);
        $feedback = array_merge($helpfulList, $irrelevantList, $harmfulList);

        foreach ($selectedList as $id) {
            $matches = 0;
            foreach ([$helpfulList, $irrelevantList, $harmfulList] as $bucket) {
                if (in_array($id, $bucket, true)) {
                    $matches++;
                }
            }
            if ($matches === 0) {
                throw new RuntimeException(sprintf("selected guidance '%s' must be marked helpful, irrelevant, or harmful", $id));
            }
            if ($matches > 1) {
                throw new RuntimeException(sprintf("selected guidance '%s' must not appear in multiple feedback buckets", $id));
            }
        }

        foreach ($feedback as $id) {
            if (!in_array($id, $selectedList, true)) {
                throw new RuntimeException(sprintf("feedback guidance '%s' was not selected for this session", $id));
            }
        }
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function logEventDraft(string $root, string $draftPath, array $data, string $actor, string $commit): string
    {
        $taskId = $this->requiredString($data, 'task_id', $draftPath);
        $compilationId = $this->requiredString($data, 'compilation_id', $draftPath);
        $taskFiles = $this->stringList($data['task_files'] ?? []);
        $evaluatedGuidance = $this->parseEvaluatedGuidance($data['evaluated_guidance'] ?? null, $draftPath);
        $guidanceOutcomes = $this->parseGuidanceOutcomes($data['guidance_outcomes'] ?? null, $draftPath);

        $knownTypes = $this->knownGuidanceTypesById($root);
        $selected = [];
        foreach ($evaluatedGuidance as $eventDraft) {
            if (!isset($knownTypes[$eventDraft->guidanceId])) {
                throw new RuntimeException(sprintf("referenced guidance '%s' does not exist in learning repository", $eventDraft->guidanceId));
            }
            if ($knownTypes[$eventDraft->guidanceId] !== $eventDraft->guidanceType) {
                throw new RuntimeException(sprintf("guidance '%s' type mismatch in outcome draft", $eventDraft->guidanceId));
            }
            if ($eventDraft->selected) {
                $selected[$eventDraft->guidanceId] = $eventDraft;
            }
        }

        $seenOutcomes = [];
        foreach ($guidanceOutcomes as $outcome) {
            $guidanceId = $outcome['guidance_id'];
            if (!isset($selected[$guidanceId])) {
                throw new RuntimeException(sprintf("outcome guidance '%s' was not selected for compilation %s", $guidanceId, $compilationId));
            }
            if (isset($seenOutcomes[$guidanceId])) {
                throw new RuntimeException(sprintf("duplicate outcome guidance '%s' in draft", $guidanceId));
            }
            if ($outcome['guidance_type'] !== $selected[$guidanceId]->guidanceType) {
                throw new RuntimeException(sprintf("outcome guidance '%s' type mismatch in draft", $guidanceId));
            }
            if ($outcome['selected'] !== true) {
                throw new RuntimeException(sprintf("outcome guidance '%s' must keep selected=true", $guidanceId));
            }
            $seenOutcomes[$guidanceId] = true;
        }

        foreach (array_keys($selected) as $guidanceId) {
            if (!isset($seenOutcomes[$guidanceId])) {
                throw new RuntimeException(sprintf("selected guidance '%s' is missing from guidance_outcomes", $guidanceId));
            }
        }

        $recordedAt = (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM);
        $selectionIds = $this->nextEventIds($root, 'recall-selections.jsonl', 'recall-selection', count($evaluatedGuidance));
        $outcomeIds = $this->nextEventIds($root, 'outcomes.jsonl', 'guidance-outcome', count($guidanceOutcomes));

        $selectionEvents = [];
        foreach ($evaluatedGuidance as $index => $eventDraft) {
            $selectionEvents[] = new RecallSelectionEvent(
                $selectionIds[$index],
                $compilationId,
                $taskId,
                $eventDraft->guidanceId,
                $eventDraft->guidanceType,
                $eventDraft->eligible,
                $eventDraft->selected,
                $eventDraft->selectionReason,
                $eventDraft->exclusionReason,
                $eventDraft->taskFiles === [] ? $taskFiles : $eventDraft->taskFiles,
                $recordedAt,
            );
        }

        $outcomeEvents = [];
        foreach ($guidanceOutcomes as $index => $outcome) {
            $outcomeEvents[] = new GuidanceOutcomeEvent(
                $outcomeIds[$index],
                $compilationId,
                $taskId,
                $outcome['guidance_id'],
                $outcome['outcome'],
                $outcome['applied'],
                $outcome['comment'],
                $commit,
                $actor,
                $recordedAt,
            );
        }

        $this->eventHistoryWriter->append($root, $selectionEvents, $outcomeEvents);

        return $compilationId;
    }

    /**
     * @return array<string, GuidanceType>
     */
    private function knownGuidanceTypesById(string $root): array
    {
        $known = [];
        foreach ($this->repository->loadActiveGuidance($root) as $guidance) {
            $known[$guidance->id] = GuidanceType::tryFrom((string)$guidance->targetType) ?? GuidanceType::SKILL;
        }
        foreach ($this->repository->loadConstraintManifests($root) as $constraint) {
            $known[$constraint->id] = GuidanceType::CONSTRAINT;
        }

        return $known;
    }

    /**
     * @return list<EvaluatedGuidance>
     */
    private function parseEvaluatedGuidance(mixed $value, string $file): array
    {
        if (!is_array($value)) {
            throw new RuntimeException('outcome draft requires evaluated_guidance list');
        }

        $items = [];
        $seen = [];
        foreach (array_values($value) as $index => $item) {
            if (!is_array($item)) {
                throw new RuntimeException(sprintf('evaluated_guidance[%d] must be an object', $index));
            }
            /** @var array<string, mixed> $item */
            $guidanceId = $this->requiredString($item, 'guidance_id', $file);
            if (isset($seen[$guidanceId])) {
                throw new RuntimeException(sprintf("duplicate evaluated guidance '%s' in draft", $guidanceId));
            }
            $seen[$guidanceId] = true;
            $guidanceType = $this->guidanceType($this->requiredString($item, 'guidance_type', $file), $guidanceId);
            $eligible = $this->requiredBool($item, 'eligible', $file);
            $selected = $this->requiredBool($item, 'selected', $file);
            $selectionReason = $this->nullableSelectionReason($item['selection_reason'] ?? null, $guidanceId);
            $exclusionReason = $this->nullableExclusionReason($item['exclusion_reason'] ?? null, $guidanceId);
            $items[] = new EvaluatedGuidance(
                $guidanceId,
                $guidanceType,
                $eligible,
                $selected,
                $selectionReason,
                $exclusionReason,
                $this->requiredStringList($item, 'task_files', $file),
                is_string($item['source_proposal'] ?? null) ? $item['source_proposal'] : null,
            );
        }

        return $items;
    }

    /**
     * @return list<array{guidance_id: string, guidance_type: GuidanceType, selected: bool, applied: bool, outcome: OutcomeValue, comment: string|null}>
     */
    private function parseGuidanceOutcomes(mixed $value, string $file): array
    {
        if (!is_array($value)) {
            throw new RuntimeException('outcome draft requires guidance_outcomes list');
        }

        $items = [];
        foreach (array_values($value) as $index => $item) {
            if (!is_array($item)) {
                throw new RuntimeException(sprintf('guidance_outcomes[%d] must be an object', $index));
            }
            /** @var array<string, mixed> $item */
            $guidanceId = $this->requiredString($item, 'guidance_id', $file);
            $outcome = OutcomeValue::tryFrom($this->requiredString($item, 'outcome', $file));
            if (!$outcome instanceof OutcomeValue) {
                throw new RuntimeException(sprintf("unknown outcome value for guidance '%s'", $guidanceId));
            }
            $comment = $item['comment'] ?? null;
            if ($comment !== null && !is_string($comment)) {
                throw new RuntimeException(sprintf("guidance outcome '%s' comment must be string or null", $guidanceId));
            }
            $items[] = [
                'guidance_id' => $guidanceId,
                'guidance_type' => $this->guidanceType($this->requiredString($item, 'guidance_type', $file), $guidanceId),
                'selected' => $this->requiredBool($item, 'selected', $file),
                'applied' => $this->requiredBool($item, 'applied', $file),
                'outcome' => $outcome,
                'comment' => $comment,
            ];
        }

        return $items;
    }

    /**
     * @return list<string>
     */
    private function nextEventIds(string $root, string $fileName, string $prefix, int $count): array
    {
        if ($count === 0) {
            return [];
        }
        $first = $this->eventHistoryWriter->nextEventId($root, $fileName, $prefix);
        $lastDot = strrpos($first, '.');
        if ($lastDot === false) {
            throw new RuntimeException('invalid generated event id: ' . $first);
        }
        $base = substr($first, 0, $lastDot + 1);
        $next = (int)substr($first, $lastDot + 1);
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $ids[] = $base . sprintf('%03d', $next + $i);
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function requiredString(array $data, string $key, string $file): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new RuntimeException(sprintf('%s requires non-empty string: %s', $file, $key));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function requiredBool(array $data, string $key, string $file): bool
    {
        $value = $data[$key] ?? null;
        if (!is_bool($value)) {
            throw new RuntimeException(sprintf('%s requires boolean: %s', $file, $key));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     * @return list<string>
     */
    private function requiredStringList(array $data, string $key, string $file): array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value)) {
            throw new RuntimeException(sprintf('%s requires string list: %s', $file, $key));
        }

        $strings = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new RuntimeException(sprintf('%s list %s must contain only strings', $file, $key));
            }
            $strings[] = $item;
        }

        return $strings;
    }

    private function guidanceType(string $value, string $guidanceId): GuidanceType
    {
        $type = GuidanceType::tryFrom($value);
        if (!$type instanceof GuidanceType) {
            throw new RuntimeException(sprintf("guidance '%s' has unknown guidance type '%s'", $guidanceId, $value));
        }

        return $type;
    }

    private function nullableSelectionReason(mixed $value, string $guidanceId): ?SelectionReason
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || !SelectionReason::tryFrom($value) instanceof SelectionReason) {
            throw new RuntimeException(sprintf("guidance '%s' has unknown selection reason", $guidanceId));
        }

        return SelectionReason::from($value);
    }

    private function nullableExclusionReason(mixed $value, string $guidanceId): ?ExclusionReason
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || !ExclusionReason::tryFrom($value) instanceof ExclusionReason) {
            throw new RuntimeException(sprintf("guidance '%s' has unknown exclusion reason", $guidanceId));
        }

        return ExclusionReason::from($value);
    }
}
