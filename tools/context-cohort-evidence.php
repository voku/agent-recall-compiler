<?php

declare(strict_types=1);

const COHORT_ROOT = 'build/context-cohort';
const LEARNING_ROOT = COHORT_ROOT . '/learning';
const MAP_INDEX = COHORT_ROOT . '/php-symbols.json';

removeTree(COHORT_ROOT);
ensureDirectory(LEARNING_ROOT);

run([
    'vendor/bin/agent-map',
    'build',
    '--root=.',
    '--paths=src,tests',
    '--out=' . MAP_INDEX,
    '--phpstan-config=phpstan.neon.dist',
]);

$scenarios = [];
$scenarios['exact_php_target'] = compileScenario(
    id: 'COHORT-EXACT',
    description: 'Bound runtime context rendering while preserving complete durable provenance.',
    files: [],
    targets: ['voku\\AgentRecallCompiler\\Rendering\\ContextExplainRenderer::render'],
    withMap: true,
);
$scenarios['known_file_unclear_symbol'] = compileScenario(
    id: 'COHORT-FILE',
    description: 'Inspect how Learning precedent runtime rendering stays bounded without assuming the exact method that needs attention.',
    files: ['src/Rendering/LearningPrecedentRenderer.php'],
    targets: [],
    withMap: true,
);
$scenarios['literal_config_task'] = compileScenario(
    id: 'COHORT-CONFIG',
    description: 'Inspect the pull-request CI workflow configuration without forcing semantic PHP navigation.',
    files: ['.github/workflows/ci.yml'],
    targets: [],
    withMap: false,
);
$scenarios['under_specified_architecture'] = compileScenario(
    id: 'COHORT-DISCOVERY',
    description: 'Orient in the Recall compilation architecture before changing how current execution context is assembled.',
    files: [],
    targets: [],
    withMap: true,
);

$checks = [
    'exact_target_has_edit_context' => factTypeCount($scenarios['exact_php_target'], 'edit_context') >= 1,
    'exact_target_has_no_architecture_discovery' => factTypeCount($scenarios['exact_php_target'], 'architecture_discovery') === 0,
    'exact_target_has_no_ranked_search' => factTypeCount($scenarios['exact_php_target'], 'navigation_candidates') === 0,
    'known_file_has_navigation_fact' => factTypeCount($scenarios['known_file_unclear_symbol'], 'navigation') >= 1,
    'known_file_has_no_edit_context' => factTypeCount($scenarios['known_file_unclear_symbol'], 'edit_context') === 0,
    'known_file_has_no_architecture_discovery' => factTypeCount($scenarios['known_file_unclear_symbol'], 'architecture_discovery') === 0,
    'literal_config_has_no_map_fact' => ($scenarios['literal_config_task']['map_fact_ids'] ?? []) === [],
    'under_specified_has_architecture_discovery' => factTypeCount($scenarios['under_specified_architecture'], 'architecture_discovery') === 1,
    'under_specified_discovery_is_ready' => ($scenarios['under_specified_architecture']['architecture_discovery_status'] ?? null) === 'ready',
    'under_specified_has_no_ranked_search' => factTypeCount($scenarios['under_specified_architecture'], 'navigation_candidates') === 0,
];

$result = [
    'schema_version' => '1.0',
    'repository' => 'voku/agent-recall-compiler',
    'source_revision' => getenv('GITHUB_SHA') ?: 'working-tree',
    'purpose' => 'Deterministic real-repository context-shape evidence for issues #143 and #174.',
    'host_observation' => [
        'status' => 'unmeasured',
        'first_additional_repository_discovery_action' => null,
        'reason' => 'This GitHub Actions cohort executes deterministic owner/compiler paths only. It does not run a coding-agent host, so downstream free-form discovery behavior stays UNKNOWN.',
    ],
    'decisions' => [
        'exact_php_target' => 'exact_typed_context_confirmed',
        'known_file_unclear_symbol' => 'deterministic_file_context_confirmed_host_sufficiency_unknown',
        'literal_config_task' => 'map_not_required',
        'automatic_architecture_discovery' => 'UNKNOWN',
        'automatic_architecture_discovery_reason' => 'The owner fact is emitted deterministically, but usefulness cannot be classified KEEP_AUTOMATIC / KEEP_EXPLICIT_ONLY / DELETE_FROM_RECALL without observing a real executor after compilation.',
        'cross_language_provider_capability' => 'UNKNOWN',
        'cross_language_provider_capability_reason' => 'Deliberately not duplicated here; released Map 0.12 provider-capability composition is owned by agent-loop#443 and its consumer proof.',
    ],
    'scenarios' => $scenarios,
    'checks' => $checks,
    'passed' => !in_array(false, $checks, true),
];

writeJson(COHORT_ROOT . '/cohort.json', $result);
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);

if (!$result['passed']) {
    throw new RuntimeException('Context cohort deterministic checks failed.');
}

/**
 * @param list<string> $files
 * @param list<string> $targets
 * @return array<string, mixed>
 */
function compileScenario(string $id, string $description, array $files, array $targets, bool $withMap): array
{
    $output = COHORT_ROOT . '/' . strtolower(str_replace('_', '-', $id));
    removeTree($output);

    $command = [
        PHP_BINARY,
        'bin/agent-recall-compiler',
        'compile',
        '--root',
        LEARNING_ROOT,
        '--task',
        $id,
        '--description',
        $description,
        '--output-dir',
        $output,
        '--compilation-id',
        'compilation.' . $id . '.context-cohort',
    ];
    foreach ($files as $file) {
        $command[] = '--file';
        $command[] = $file;
    }
    foreach ($targets as $target) {
        $command[] = '--target';
        $command[] = $target;
    }
    if ($withMap) {
        $command[] = '--map-index';
        $command[] = MAP_INDEX;
        $command[] = '--map-root';
        $command[] = '.';
    }

    run($command);

    $factsDocument = readJson($output . '/facts.json');
    $facts = is_array($factsDocument['facts'] ?? null) ? $factsDocument['facts'] : [];
    $selection = readJson($output . '/selection-report.json');
    $contextExplain = is_array($selection['context_explain'] ?? null) ? $selection['context_explain'] : [];

    $factTypes = [];
    $mapFactIds = [];
    $architectureStatus = null;
    $searchStatus = null;
    foreach ($facts as $fact) {
        if (!is_array($fact)) {
            continue;
        }
        $type = is_string($fact['type'] ?? null) ? $fact['type'] : 'unknown';
        $factTypes[$type] = ($factTypes[$type] ?? 0) + 1;
        $factId = is_string($fact['id'] ?? null) ? $fact['id'] : null;
        if ($factId !== null && str_starts_with($factId, 'map.')) {
            $mapFactIds[] = $factId;
        }
        $payload = is_array($fact['payload'] ?? null) ? $fact['payload'] : [];
        if ($type === 'architecture_discovery') {
            $architectureStatus = is_string($payload['status'] ?? null) ? $payload['status'] : null;
        }
        if ($type === 'navigation_candidates') {
            $searchStatus = is_string($payload['status'] ?? null) ? $payload['status'] : 'ranked';
        }
    }
    ksort($factTypes, SORT_STRING);
    sort($mapFactIds, SORT_STRING);

    $explainKinds = [];
    $omissionReasons = [];
    foreach ($contextExplain as $item) {
        if (!is_array($item)) {
            continue;
        }
        $kind = is_string($item['kind'] ?? null) ? $item['kind'] : 'unknown';
        $explainKinds[$kind] = ($explainKinds[$kind] ?? 0) + 1;
        $whyNot = is_string($item['why_not'] ?? null) ? trim($item['why_not']) : '';
        if ($whyNot !== '') {
            $omissionReasons[$whyNot] = ($omissionReasons[$whyNot] ?? 0) + 1;
        }
    }
    ksort($explainKinds, SORT_STRING);
    ksort($omissionReasons, SORT_STRING);

    $system = file_get_contents($output . '/system.md');
    if (!is_string($system)) {
        throw new RuntimeException('Cannot read compiled system.md for ' . $id);
    }

    return [
        'task_id' => $id,
        'input' => [
            'description' => $description,
            'files' => $files,
            'targets' => $targets,
            'map_configured' => $withMap,
            'search_configured' => false,
        ],
        'fact_types' => $factTypes,
        'map_fact_ids' => $mapFactIds,
        'context_explain_kinds' => $explainKinds,
        'omission_reasons' => $omissionReasons,
        'architecture_discovery_status' => $architectureStatus,
        'search_status' => $searchStatus,
        'system_bytes' => strlen($system),
        'first_additional_repository_discovery_action' => null,
        'executor_observation_status' => 'UNKNOWN_NO_HOST',
    ];
}

/** @param array<string, mixed> $scenario */
function factTypeCount(array $scenario, string $type): int
{
    $factTypes = is_array($scenario['fact_types'] ?? null) ? $scenario['fact_types'] : [];
    $count = $factTypes[$type] ?? 0;

    return is_int($count) ? $count : 0;
}

/** @param list<string> $command */
function run(array $command): void
{
    $process = proc_open(
        $command,
        [
            0 => ['file', 'php://stdin', 'r'],
            1 => ['file', 'php://stdout', 'w'],
            2 => ['file', 'php://stderr', 'w'],
        ],
        $pipes,
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start command: ' . implode(' ', $command));
    }
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        throw new RuntimeException(sprintf('Command failed with exit code %d: %s', $exitCode, implode(' ', $command)));
    }
}

function ensureDirectory(string $path): void
{
    if (!is_dir($path) && !mkdir($path, 0o775, true) && !is_dir($path)) {
        throw new RuntimeException('Cannot create directory: ' . $path);
    }
}

function removeTree(string $path): void
{
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        if (!unlink($path)) {
            throw new RuntimeException('Cannot remove file: ' . $path);
        }

        return;
    }
    $items = scandir($path);
    if ($items === false) {
        throw new RuntimeException('Cannot read directory: ' . $path);
    }
    foreach ($items as $item) {
        if ($item !== '.' && $item !== '..') {
            removeTree($path . '/' . $item);
        }
    }
    if (!rmdir($path)) {
        throw new RuntimeException('Cannot remove directory: ' . $path);
    }
}

/** @return array<string, mixed> */
function readJson(string $path): array
{
    $content = file_get_contents($path);
    if (!is_string($content)) {
        throw new RuntimeException('Cannot read JSON file: ' . $path);
    }
    $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException('JSON root is not an object: ' . $path);
    }

    return $decoded;
}

/** @param array<string, mixed> $data */
function writeJson(string $path, array $data): void
{
    ensureDirectory(dirname($path));
    $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    if (file_put_contents($path, $encoded) === false) {
        throw new RuntimeException('Cannot write JSON file: ' . $path);
    }
}
