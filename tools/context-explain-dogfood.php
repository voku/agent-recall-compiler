<?php

declare(strict_types=1);

const TASK_ID = 'ARC-17';
const ACTOR = 'context-explain-dogfood';
const STATE_ROOT = '.agent-loop';
const LEARNING_ROOT = STATE_ROOT . '/learning';
const RECALL_ROOT = STATE_ROOT . '/recall';
const TASKS_ROOT = STATE_ROOT . '/tasks';
const MAP_INDEX = STATE_ROOT . '/map/php-symbols.json';
const REPORT_DIR = 'build/context-explain-dogfood';
const TARGET_DIR = 'build/context-explain-targeted';

$agentLoopBin = envOrDefault('AGENT_LOOP_BIN', 'build/agent-loop/bin/agent-loop');
$agentMapBin = envOrDefault('AGENT_MAP_BIN', 'build/agent-loop/vendor/bin/agent-map');
$agentRecallBin = envOrDefault('AGENT_RECALL_BIN', 'build/agent-loop/vendor/bin/agent-recall-compiler');
$promptManifest = envOrDefault('PROMPT_MANIFEST', 'skills/agent-recall-consumer/operating-prompts.json');

removeTree(STATE_ROOT);
removeTree(REPORT_DIR);
removeTree(TARGET_DIR);
ensureDirectory(REPORT_DIR);

run([PHP_BINARY, $agentLoopBin, 'init', 'scaffold']);
ensureDirectory(TASKS_ROOT);
ensureDirectory(LEARNING_ROOT);
writeFile(
    TASKS_ROOT . '/' . TASK_ID . '.md',
    sprintf("# %s\n\nGovern context-explain implementation through the real agent-loop workflow.\n", TASK_ID),
);
run([
    PHP_BINARY,
    $agentLoopBin,
    'board',
    'card',
    'create',
    TASK_ID,
    '--title=Explain why and how recall context was selected',
    '--lane=READY',
    '--status=Selected',
]);

writeJson(LEARNING_ROOT . '/recall-documents.json', [
    'schema_version' => '1.0',
    'documents' => [[
        'id' => 'project.operating-prompts',
        'type' => 'adr',
        'source' => '../../docs/operating-prompts.md',
        'scope' => ['src/'],
        'tags' => ['recall', 'prompting'],
        'max_chars' => 2400,
    ]],
]);

run([
    $agentMapBin,
    'build',
    '--root=.',
    '--paths=src,tests',
    '--out=' . MAP_INDEX,
    '--phpstan-config=phpstan.neon.dist',
]);

run([
    PHP_BINARY,
    $agentLoopBin,
    'workflow',
    'plan',
    TASK_ID,
    '--by',
    ACTOR,
    '--file',
    'src/Compilation/RecallCompilationService.php',
    '--file',
    'src/Provider/ProjectCapabilityRecallProvider.php',
    '--goal',
    'Make recall context selection explainable from deterministic repository evidence.',
    '--non-goal',
    'Do not create a universal evidence subsystem or LLM-generated rationale.',
    '--validation',
    'composer ci',
    '--tag',
    'recall',
    '--tag',
    'prompting',
    '--behavior-anchor',
    'compiled recall facts -> context explain projection -> receiving agent decision',
    '--operating-prompt-manifest',
    $promptManifest,
    '--operating-prompt',
    '{"id":"multi-pass-correctness-simplify","arguments":{}}',
]);

run([PHP_BINARY, $agentLoopBin, 'workflow', 'approve', TASK_ID, '--by', ACTOR]);
runToFile([PHP_BINARY, $agentLoopBin, 'workflow', 'context', TASK_ID], REPORT_DIR . '/workflow-context.txt');

$selectionReport = findFirstFile(
    RECALL_ROOT,
    static fn (string $path): bool => basename($path) === 'selection-report.json'
        && basename(dirname($path)) === TASK_ID,
);
if ($selectionReport === null) {
    fail('context explain dogfood: selection-report.json not found');
}

$recallDir = dirname($selectionReport);
copyFile($selectionReport, REPORT_DIR . '/selection-report.json');
copyFile($recallDir . '/system.md', REPORT_DIR . '/system.md');

$selectionData = readJson($selectionReport);
$items = $selectionData['context_explain'] ?? null;
if (!is_array($items)) {
    fail('context explain dogfood: context_explain is missing from selection-report.json', 42);
}

$composerCi = findItem($items, static fn (array $item): bool => ($item['what'] ?? null) === 'composer ci');
$tool = findItem($items, static fn (array $item): bool => ($item['kind'] ?? null) === 'tool_presence');
$document = findItem($items, static fn (array $item): bool => ($item['what'] ?? null) === 'docs/operating-prompts.md');
$recipe = findItem($items, static fn (array $item): bool => ($item['what'] ?? null) === 'multi-pass-correctness-simplify (L2)');
$system = readFileStrict($recallDir . '/system.md');

$checks = [
    'composer_ci_verified' => is_array($composerCi)
        && ($composerCi['state'] ?? null) === 'verified'
        && ($composerCi['use'] ?? null) === 'verification_candidate'
        && str_contains((string) ($composerCi['how'] ?? ''), 'composer.json scripts.ci'),
    'tool_presence_is_not_command' => is_array($tool)
        && ($tool['use'] ?? null) === 'capability_presence_only_do_not_infer_command'
        && str_contains((string) ($tool['how'] ?? ''), 'does not prove'),
    'document_has_selection_reason' => is_array($document)
        && ($document['state'] ?? null) === 'verified'
        && str_contains((string) ($document['why'] ?? ''), 'scope overlap')
        && str_contains((string) ($document['why'] ?? ''), 'tag overlap'),
    'l2_recipe_authority' => is_array($recipe)
        && ($recipe['authority'] ?? null) === 'approved_session_brief'
        && ($recipe['use'] ?? null) === 'construct_project_specific_l1_contract',
    'system_renders_provenance_not_rationale' => str_contains($system, '## Context Explain Plan')
        && str_contains($system, "not the implementing agent's rationale"),
    'verified_state_is_provenance_not_content_truth' => str_contains(
        $system,
        'VERIFIED` does not mean every statement inside the referenced source is automatically correct',
    ),
];
writeJson(REPORT_DIR . '/result.json', [
    'schema_version' => '1.0',
    'task_id' => TASK_ID,
    'selection_report' => $selectionReport,
    'checks' => $checks,
    'passed' => !in_array(false, $checks, true),
]);
assertChecks($checks, 'context explain semantic check', 43);

run([
    PHP_BINARY,
    $agentRecallBin,
    'compile',
    '--root',
    LEARNING_ROOT,
    '--task',
    'ARC-17-TARGET',
    '--description',
    'Explain the context selected for ContextExplainProjector::project.',
    '--target',
    'voku\\AgentRecallCompiler\\Context\\ContextExplainProjector::project',
    '--map-index',
    MAP_INDEX,
    '--map-root',
    '.',
    '--output-dir',
    TARGET_DIR,
    '--compilation-id',
    'compilation.ARC-17-TARGET.dogfood',
]);

$targetData = readJson(TARGET_DIR . '/selection-report.json');
$targetItems = is_array($targetData['context_explain'] ?? null) ? $targetData['context_explain'] : [];
$primary = findItem(
    $targetItems,
    static fn (array $item): bool => ($item['use'] ?? null) === 'implementation_candidate'
        && ($item['state'] ?? null) === 'verified',
);
$contextOnly = findItem(
    $targetItems,
    static fn (array $item): bool => ($item['use'] ?? null) === 'context_only_do_not_edit_from_selection_alone',
);
$targetChecks = [
    'primary_verified' => is_array($primary),
    'primary_authority_is_repository_source' => is_array($primary)
        && ($primary['authority'] ?? null) === 'repository_source_via_agent_map'
        && str_contains((string) ($primary['how'] ?? ''), 'agent-map EditContextPlan role(s): primary'),
    'context_only_present' => is_array($contextOnly),
    'context_only_has_no_edit_permission' => is_array($contextOnly)
        && ($contextOnly['authority'] ?? null) === 'repository_source_via_agent_map'
        && str_starts_with((string) ($contextOnly['use'] ?? ''), 'context_only_'),
];
writeJson(REPORT_DIR . '/targeted-result.json', [
    'schema_version' => '1.0',
    'checks' => $targetChecks,
    'passed' => !in_array(false, $targetChecks, true),
]);
assertChecks($targetChecks, 'targeted context explain semantic check', 44);

writeFile(REPORT_DIR . '/l1.md', <<<'MARKDOWN'
## Goal
Expose deterministic context provenance so a receiving agent can distinguish what was selected, why it matters, how Recall derived it, what authority it carries, how it may be used, and which evidence remains unknown or excluded.

## Context
The approved task is ARC-17. `selection-report.json` exposes project-native `composer ci` from `composer.json`, the scoped `docs/operating-prompts.md` ADR, and the selected `multi-pass-correctness-simplify` L2 recipe. Target-aware agent-map context is verified separately against `ContextExplainProjector::project`.

## Constraints
Do not invent commands from installed package names. Do not turn dependency or type-definition context into edit permission. Do not inject implementation rationale as context provenance. Keep UNKNOWN valid when evidence cannot support a stronger state.

## Verification
Run `composer ci`. Run the semantic dogfood checks against governed and target-aware Recall artifacts. Verify that detected tool presence does not become an invented project command. Generate and inspect the agent-loop blind-spot review.

## Done When
`composer ci` passes, semantic context-explain checks pass, target-aware Recall exposes a verified implementation candidate, dependencies remain context-only, tool presence does not become an invented command, and the review artifact is generated and inspected without weakening the approved contract.
MARKDOWN
    . PHP_EOL);

run([
    PHP_BINARY,
    $agentLoopBin,
    'workflow',
    'contract',
    TASK_ID,
    '--status',
    'ready',
    '--from',
    REPORT_DIR . '/l1.md',
    '--by',
    ACTOR,
]);

$validationStarted = hrtime(true);
runStreamingWithLog(['composer', 'ci'], REPORT_DIR . '/composer-ci.log');
$validationDurationMs = intdiv(hrtime(true) - $validationStarted, 1_000_000);

$runPath = STATE_ROOT . '/runs/' . TASK_ID . '/run.json';
$runData = readJson($runPath);
$contractRevision = $runData['contract_revision'] ?? null;
if (!is_int($contractRevision) || $contractRevision < 1) {
    fail('context explain dogfood: governed Run has no positive contract_revision', 46);
}

run([
    PHP_BINARY,
    $agentLoopBin,
    'session',
    'validation',
    'record',
    TASK_ID,
    '--contract-revision',
    (string) $contractRevision,
    '--command',
    'composer ci',
    '--status',
    'passed',
    '--exit-code',
    '0',
    '--duration-ms',
    (string) $validationDurationMs,
    '--by',
    ACTOR,
]);

$reviewExit = runToFile(
    [PHP_BINARY, $agentLoopBin, 'review', 'blindspots', TASK_ID],
    REPORT_DIR . '/review-before-checkpoint.txt',
    true,
);
writeFile(REPORT_DIR . '/review-before-checkpoint.exit', $reviewExit . PHP_EOL);
if ($reviewExit !== 0) {
    fail(sprintf('context explain dogfood: review blindspots exited with %d', $reviewExit), 45);
}

$reviewArtifact = findFirstFile(
    '.',
    static fn (string $path): bool => basename($path) === TASK_ID . '.blindspots.json',
);
if ($reviewArtifact === null) {
    fail('context explain dogfood: blind-spot review artifact not found', 45);
}
copyFile($reviewArtifact, REPORT_DIR . '/blindspots.json');

run([
    PHP_BINARY,
    $agentLoopBin,
    'session',
    'checkpoint',
    TASK_ID,
    '--title',
    'Review',
    '--body',
    'review blindspots ' . TASK_ID . ' was generated and inspected by context-explain dogfood.',
]);

runToFile([PHP_BINARY, $agentLoopBin, 'workflow', 'status', TASK_ID], REPORT_DIR . '/workflow-status.txt');

fwrite(STDOUT, "[OK] context explain dogfood: governed context, contract, validation, target-aware evidence, unsupported inference boundary, and review were exercised\n");

function envOrDefault(string $name, string $default): string
{
    $value = getenv($name);

    return is_string($value) && $value !== '' ? $value : $default;
}

function ensureDirectory(string $path): void
{
    if (is_dir($path)) {
        return;
    }
    if (!mkdir($path, 0777, true) && !is_dir($path)) {
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
        if ($item === '.' || $item === '..') {
            continue;
        }
        removeTree($path . '/' . $item);
    }
    if (!rmdir($path)) {
        throw new RuntimeException('Cannot remove directory: ' . $path);
    }
}

/** @param list<string> $command */
function run(array $command, bool $allowFailure = false): int
{
    $process = proc_open($command, [
        0 => ['file', 'php://stdin', 'r'],
        1 => ['file', 'php://stdout', 'w'],
        2 => ['file', 'php://stderr', 'w'],
    ], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start command: ' . commandDisplay($command));
    }
    $exitCode = proc_close($process);
    if ($exitCode !== 0 && !$allowFailure) {
        throw new RuntimeException(sprintf('Command failed with exit code %d: %s', $exitCode, commandDisplay($command)));
    }

    return $exitCode;
}

/** @param list<string> $command */
function runToFile(array $command, string $outputPath, bool $allowFailure = false): int
{
    ensureDirectory(dirname($outputPath));
    $process = proc_open($command, [
        0 => ['file', 'php://stdin', 'r'],
        1 => ['file', $outputPath, 'w'],
        2 => ['file', $outputPath, 'a'],
    ], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start command: ' . commandDisplay($command));
    }
    $exitCode = proc_close($process);
    if ($exitCode !== 0 && !$allowFailure) {
        throw new RuntimeException(sprintf('Command failed with exit code %d: %s', $exitCode, commandDisplay($command)));
    }

    return $exitCode;
}

/** @param list<string> $command */
function runStreamingWithLog(array $command, string $logPath): void
{
    ensureDirectory(dirname($logPath));
    $log = fopen($logPath, 'wb');
    if ($log === false) {
        throw new RuntimeException('Cannot open log file: ' . $logPath);
    }

    $process = proc_open($command, [
        0 => ['file', 'php://stdin', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);
    if (!is_resource($process)) {
        fclose($log);
        throw new RuntimeException('Cannot start command: ' . commandDisplay($command));
    }

    foreach ([1, 2] as $index) {
        stream_set_blocking($pipes[$index], false);
    }

    $open = [1 => true, 2 => true];
    while ($open !== []) {
        $read = [];
        foreach (array_keys($open) as $index) {
            $read[] = $pipes[$index];
        }
        $write = null;
        $except = null;
        $ready = stream_select($read, $write, $except, null);
        if ($ready === false) {
            foreach (array_keys($open) as $index) {
                fclose($pipes[$index]);
            }
            proc_terminate($process);
            proc_close($process);
            fclose($log);
            throw new RuntimeException('Cannot read command output: ' . commandDisplay($command));
        }

        foreach ($read as $stream) {
            $index = $stream === $pipes[1] ? 1 : 2;
            $chunk = fread($stream, 8192);
            if ($chunk === false) {
                continue;
            }
            if ($chunk !== '') {
                fwrite($index === 1 ? STDOUT : STDERR, $chunk);
                fwrite($log, $chunk);
            }
            if (feof($stream)) {
                fclose($stream);
                unset($open[$index]);
            }
        }
    }

    $exitCode = proc_close($process);
    fclose($log);
    if ($exitCode !== 0) {
        throw new RuntimeException(sprintf('Command failed with exit code %d: %s', $exitCode, commandDisplay($command)));
    }
}

/** @param list<string> $command */
function commandDisplay(array $command): string
{
    return implode(' ', array_map(static fn (string $part): string => escapeshellarg($part), $command));
}

function writeFile(string $path, string $content): void
{
    ensureDirectory(dirname($path));
    if (file_put_contents($path, $content) === false) {
        throw new RuntimeException('Cannot write file: ' . $path);
    }
}

/** @param array<string, mixed> $data */
function writeJson(string $path, array $data): void
{
    writeFile(
        $path,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL,
    );
}

/** @return array<string, mixed> */
function readJson(string $path): array
{
    $data = json_decode(readFileStrict($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data)) {
        throw new RuntimeException('JSON root must be an object: ' . $path);
    }

    return $data;
}

function readFileStrict(string $path): string
{
    $content = file_get_contents($path);
    if ($content === false) {
        throw new RuntimeException('Cannot read file: ' . $path);
    }

    return $content;
}

function copyFile(string $source, string $destination): void
{
    ensureDirectory(dirname($destination));
    if (!copy($source, $destination)) {
        throw new RuntimeException(sprintf('Cannot copy %s to %s', $source, $destination));
    }
}

function findFirstFile(string $root, callable $predicate): ?string
{
    if (!is_dir($root)) {
        return null;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    );
    foreach ($iterator as $item) {
        if (!$item instanceof SplFileInfo || !$item->isFile()) {
            continue;
        }
        $path = str_replace('\\', '/', $item->getPathname());
        if ($predicate($path)) {
            return $path;
        }
    }

    return null;
}

/**
 * @param array<array-key, mixed> $items
 * @return array<string, mixed>|null
 */
function findItem(array $items, callable $predicate): ?array
{
    foreach ($items as $item) {
        if (is_array($item) && $predicate($item)) {
            return $item;
        }
    }

    return null;
}

/** @param array<string, bool> $checks */
function assertChecks(array $checks, string $label, int $exitCode): void
{
    $failed = [];
    foreach ($checks as $name => $passed) {
        if (!$passed) {
            $failed[] = $name;
        }
    }
    if ($failed === []) {
        return;
    }
    foreach ($failed as $name) {
        fwrite(STDERR, sprintf("[FAIL] %s: %s\n", $label, $name));
    }
    exit($exitCode);
}

function fail(string $message, int $exitCode = 1): never
{
    fwrite(STDERR, '[FAIL] ' . $message . PHP_EOL);
    exit($exitCode);
}
