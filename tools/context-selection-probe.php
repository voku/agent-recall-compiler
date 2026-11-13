<?php

declare(strict_types=1);

/**
 * Read-only, current-head Recall context-shape probe for issue #174.
 *
 * This proves deterministic owner projection and reports its byte cost. It does
 * not measure coding-agent decisions, correctness, token consumption, or cost.
 */

const PROBE_MAP_INDEX = '.agent-loop/map/php-symbols.json';
const PROBE_LEARNING_ROOT = '.agent-loop/learning';
const PROBE_RECALL_BIN = 'build/agent-loop/vendor/bin/agent-recall-compiler';
const PROBE_OUTPUT_ROOT = 'build/context-selection-probe';

foreach ([PROBE_MAP_INDEX, PROBE_RECALL_BIN] as $requiredFile) {
    if (!is_file($requiredFile)) {
        throw new RuntimeException('Missing required owner artifact: ' . $requiredFile);
    }
}
if (!is_dir(PROBE_LEARNING_ROOT)) {
    throw new RuntimeException('Missing Learning root: ' . PROBE_LEARNING_ROOT);
}
if (!is_dir(PROBE_OUTPUT_ROOT) && !mkdir(PROBE_OUTPUT_ROOT, 0775, true) && !is_dir(PROBE_OUTPUT_ROOT)) {
    throw new RuntimeException('Cannot create ' . PROBE_OUTPUT_ROOT);
}

$target = 'voku\\AgentRecallCompiler\\Context\\ContextExplainProjector::project';
$file = 'src/Context/ContextExplainProjector.php';
if (!is_file($file)) {
    throw new RuntimeException('Missing known source file: ' . $file);
}

/** @var array<string, array{description: string, arguments: list<string>, expected_type: string, excluded_type: string}> $cases */
$cases = [
    'exact_target' => [
        'description' => 'Inspect ContextExplainProjector::project and identify exact PHP implementation context.',
        'arguments' => ['--target', $target],
        'expected_type' => 'edit_context',
        'excluded_type' => 'architecture_discovery',
    ],
    'known_file' => [
        'description' => 'Inspect the known ContextExplainProjector.php source file before editing.',
        'arguments' => ['--file', $file],
        'expected_type' => 'navigation',
        'excluded_type' => 'architecture_discovery',
    ],
    'unknown_location' => [
        'description' => 'Find the architectural owners of Recall context explanation before inspecting code.',
        'arguments' => [],
        'expected_type' => 'architecture_discovery',
        'excluded_type' => 'edit_context',
    ],
];

$results = [];
foreach ($cases as $name => $case) {
    $runs = [];
    foreach (['first', 'repeat'] as $runName) {
        $dir = PROBE_OUTPUT_ROOT . '/' . $name . '-' . $runName;
        $arguments = [
            PHP_BINARY, PROBE_RECALL_BIN, 'compile',
            '--root', PROBE_LEARNING_ROOT,
            '--task', 'ARC-174-' . strtoupper(str_replace('_', '-', $name)),
            '--description', $case['description'],
            '--map-index', PROBE_MAP_INDEX,
            '--map-root', '.',
            '--output-dir', $dir,
            '--compilation-id', 'compilation.ARC-174.' . $name . '.probe',
            ...$case['arguments'],
        ];
        $process = proc_open($arguments, [0 => ['file', '/dev/null', 'r'], 1 => ['file', 'php://stdout', 'w'], 2 => ['file', 'php://stderr', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start Recall compile: ' . $name);
        }
        $exit = proc_close($process);
        if ($exit !== 0) {
            throw new RuntimeException('Recall compile failed for ' . $name . ' (' . $runName . ') with exit ' . $exit);
        }

        $json = file_get_contents($dir . '/facts.json');
        if ($json === false) {
            throw new RuntimeException('Cannot read facts document for ' . $name);
        }
        /** @var mixed $decoded */
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || !isset($decoded['facts']) || !is_array($decoded['facts'])) {
            throw new RuntimeException('Invalid facts document for ' . $name);
        }
        $types = [];
        foreach ($decoded['facts'] as $fact) {
            if (!is_array($fact) || !is_string($fact['type'] ?? null)) {
                throw new RuntimeException('Invalid owner fact in ' . $name);
            }
            $types[$fact['type']] = ($types[$fact['type']] ?? 0) + 1;
        }
        ksort($types, SORT_STRING);
        $expected = $case['expected_type'];
        $excluded = $case['excluded_type'];
        if (($types[$expected] ?? 0) < 1 || ($types[$excluded] ?? 0) !== 0) {
            throw new RuntimeException(sprintf('%s: expected %s and no %s, got %s', $name, $expected, $excluded, json_encode($types, JSON_THROW_ON_ERROR)));
        }
        $system = file_get_contents($dir . '/system.md');
        if ($system === false) {
            throw new RuntimeException('Cannot read compiled system.md for ' . $name);
        }
        $runs[$runName] = [
            'fact_types' => $types,
            'system_bytes' => strlen($system),
            'facts_bytes' => strlen($json),
            'system_sha256' => hash('sha256', $system),
            'facts_sha256' => hash('sha256', $json),
        ];
    }
    if ($runs['first'] !== $runs['repeat']) {
        throw new RuntimeException('Nondeterministic Recall projection for ' . $name);
    }
    $results[$name] = $runs['first'];
}

$head = trim((string) shell_exec('git rev-parse HEAD 2>/dev/null'));
if (preg_match('/^[0-9a-f]{40}$/', $head) !== 1) {
    throw new RuntimeException('Cannot determine checkout SHA');
}
$mapHash = hash_file('sha256', PROBE_MAP_INDEX);
if (!is_string($mapHash)) {
    throw new RuntimeException('Cannot hash map');
}
$report = [
    'schema_version' => '1.0',
    'evidence_class' => 'DETERMINISTIC_OWNER_PROJECTION_NO_AGENT_OUTCOME',
    'checkout_sha' => $head,
    'map_sha256' => $mapHash,
    'source_file' => $file,
    'source_sha256' => hash_file('sha256', $file),
    'owner_target' => $target,
    'search_index_configured' => false,
    'cases' => $results,
    'limitations' => [
        'One current repository and three controlled task shapes, not independent historical engineering tasks.',
        'Standalone Recall CLI owner defaults are measured here, not the narrower agent-loop consumer policy (6 files / 16 KB at the prior frozen consumer).',
        'Measures projection presence and bytes only; no coding agent, correctness, tool use, tokens or causal efficiency measured.',
        'The map was prepared by the existing governed context-explain dogfood; no new Map indexer or owner API was introduced.',
    ],
];
$serialized = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
if (file_put_contents(PROBE_OUTPUT_ROOT . '/report.json', $serialized) === false) {
    throw new RuntimeException('Cannot write probe report');
}
fwrite(STDOUT, '[OK] Recall context-shape probe: owner facts, byte counts and repeated projection digests are verified' . PHP_EOL);
