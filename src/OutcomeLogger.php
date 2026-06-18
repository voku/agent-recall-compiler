<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler;

use DateTimeImmutable;
use DateTimeInterface;
use RuntimeException;

final class OutcomeLogger
{
    private readonly RecallRepository $repository;

    public function __construct()
    {
        $this->repository = new RecallRepository();
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
}
