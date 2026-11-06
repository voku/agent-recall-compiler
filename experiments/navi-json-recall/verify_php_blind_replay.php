<?php

declare(strict_types=1);

use voku\AgentRecallCompiler\Selection\BoundedChoicePromptBuilder;
use voku\AgentRecallCompiler\Selection\BoundedChoiceValidator;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

/**
 * Replay unverified local-model transcript through the actual PHP contract.
 *
 * @phpstan-type RecordedCase array{
 *   query: string,
 *   llm_raw_response: string,
 *   llm_candidates_offered: list<string>,
 *   predictions: array{live_llm_bounded_choice: list<string>}
 * }
 *
 * @param non-empty-string $filepath
 *
 * @return array{cases: list<RecordedCase>}
 */
function readRecordedEvidence(string $filepath): array
{
    if (!is_file($filepath) || is_link($filepath)) {
        throw new RuntimeException('Recorded evidence is missing or symlinked: ' . $filepath);
    }

    $bytes = file_get_contents($filepath);
    if ($bytes === false) {
        throw new RuntimeException('Unable to read recorded evidence: ' . $filepath);
    }

    /** @var mixed $decoded */
    $decoded = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);

    if (!is_array($decoded) || ($decoded['case_count'] ?? null) !== 26 || !isset($decoded['cases']) || !is_array($decoded['cases']) || count($decoded['cases']) !== 26) {
        throw new RuntimeException('Expected 26 recorded blind holdout responses');
    }

    /** @var array{cases: list<RecordedCase>} $decoded */
    return $decoded;
}

$evidencePath = $argv[1] ?? '';
if ($evidencePath === '') {
    throw new InvalidArgumentException('Usage: php verify_php_blind_replay.php <recorded-evidence.json>');
}

$evidence = readRecordedEvidence($evidencePath);
$builder = new BoundedChoicePromptBuilder();
$validator = new BoundedChoiceValidator();
$verified = 0;
$empty = 0;

foreach ($evidence['cases'] as $index => $case) {
    $raw = $case['llm_raw_response'] ?? null;
    $candidateIds = $case['llm_candidates_offered'] ?? null;
    $predicted = $case['predictions']['live_llm_bounded_choice'] ?? null;
    $query = $case['query'] ?? null;

    if (!is_string($raw) || !is_string($query) || trim($query) === '' || !is_array($candidateIds) || !is_array($predicted)) {
        throw new RuntimeException('Invalid transcript case structure at index ' . $index);
    }

    foreach ($candidateIds as $candidateId) {
        if (!is_string($candidateId) || $candidateId === '') {
            throw new RuntimeException('Invalid candidate ID in case ' . $index);
        }
    }

    if (count($candidateIds) !== count(array_unique($candidateIds))) {
        throw new RuntimeException('Duplicate candidate ID at case ' . $index);
    }

    $phpResult = $validator->validate($raw, $candidateIds);
    $expected = $phpResult === null ? [] : [$phpResult];

    if ($expected !== $predicted) {
        throw new RuntimeException(sprintf('PHP validator disagrees with recorded model choice at case %d', $index));
    }

    // Prove that the real PHP prompt builder can format the same candidates.
    // The live local CLI used a Python mirror; exact prompt parity is not implied.
    $candidates = [];
    foreach ($candidateIds as $candidateId) {
        $candidates[] = [
            'id' => $candidateId,
            'title' => $candidateId,
            'description' => 'Authoritative source-backed candidate',
        ];
    }

    $prompt = $builder->build($query, $candidates);
    if (!str_contains($prompt, '{"selected": "cand_<index>"}')) {
        throw new RuntimeException('PHP prompt missing bounded choice contract');
    }

    if ($raw === '') {
        ++$empty;
    }

    ++$verified;
}

if ($verified !== 26) {
    throw new RuntimeException('Unverified replay cases remain');
}

echo json_encode([
    'status' => 'pass',
    'verified' => $verified,
    'empty_response_count' => $empty,
    'verification' => 'recorded-local-outputs-only; no live LLM inference',
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL;
