<?php

declare(strict_types=1);

use voku\AgentRecallCompiler\Selection\BoundedChoiceValidator;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$path = $argv[1] ?? null;
if (!is_string($path) || $path === '' || !is_file($path) || is_link($path)) {
    throw new InvalidArgumentException('Usage: php verify_php_applicability_ab.php <evidence.json>');
}

$bytes = file_get_contents($path);
if ($bytes === false || strlen($bytes) > 1024 * 1024) {
    throw new RuntimeException('Could not safely read bounded A/B evidence');
}

/** @var mixed $report */
$report = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
if (!is_array($report) || ($report['case_count'] ?? null) !== 10
    || !isset($report['cases']) || !is_array($report['cases']) || count($report['cases']) !== 10
) {
    throw new RuntimeException('A/B report must contain exactly ten decisions');
}

$validator = new BoundedChoiceValidator();
$counts = [
    'variant_a_legacy' => ['correct_with_fail_closed' => 0, 'empty_response' => 0, 'model_error' => 0, 'explicit_none' => 0],
    'variant_b_contract' => ['correct_with_fail_closed' => 0, 'empty_response' => 0, 'model_error' => 0, 'explicit_none' => 0],
];

foreach ($report['cases'] as $index => $case) {
    if (!is_array($case) || !isset($case['candidates_offered']) || !is_array($case['candidates_offered'])
        || !array_is_list($case['candidates_offered'])
    ) {
        throw new RuntimeException(sprintf('Invalid candidate list at case %d', $index));
    }

    /** @var mixed $candidateIds */
    $candidateIds = $case['candidates_offered'];
    foreach ($candidateIds as $candidateId) {
        if (!is_string($candidateId) || $candidateId === '') {
            throw new RuntimeException(sprintf('Invalid candidate ID at case %d', $index));
        }
    }

    if (count($candidateIds) !== count(array_unique($candidateIds))) {
        throw new RuntimeException(sprintf('Duplicate candidate IDs at case %d', $index));
    }

    /** @var mixed $contractExpected */
    $contractExpected = $case['expected_contract'] ?? null;
    if ($contractExpected !== null && !is_string($contractExpected)) {
        throw new RuntimeException(sprintf('Invalid gold label at case %d', $index));
    }

    foreach ($counts as $variant => &$metrics) {
        /** @var mixed $record */
        $record = $case[$variant] ?? null;
        if (!is_array($record) || !isset($record['raw']) || !is_string($record['raw'])) {
            throw new RuntimeException(sprintf('Missing model output in case %d (%s)', $index, $variant));
        }

        /** @var mixed $modelError */
        $modelError = $record['model_error'] ?? null;
        if ($modelError !== null && (!is_string($modelError) || $modelError === '' || $record['raw'] !== '')) {
            throw new RuntimeException(sprintf('Invalid model transport error at case %d (%s)', $index, $variant));
        }
        $selected = $validator->validate($record['raw'], $candidateIds);
        $correct = $modelError === null && $selected === $contractExpected;
        if (($record['selected'] ?? null) !== $selected
            || ($record['correct_vs_contract'] ?? null) !== $correct
        ) {
            throw new RuntimeException(sprintf('PHP replay disagrees with A/B evidence at case %d (%s)', $index, $variant));
        }

        if ($correct) {
            ++$metrics['correct_with_fail_closed'];
        }
        if ($modelError !== null) {
            ++$metrics['model_error'];
        } elseif (trim($record['raw']) === '') {
            ++$metrics['empty_response'];
        }
        if (preg_match('/^\s*\{\s*"selected"\s*:\s*"none"\s*\}\s*$/D', $record['raw']) === 1) {
            ++$metrics['explicit_none'];
        }
    }
    unset($metrics);
}

echo json_encode([
    'status' => 'pass',
    'evidence_class' => 'stored-local-model-outputs-not-live-CI-inference',
    'cases_replayed' => 10,
    'metrics' => $counts,
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL;
