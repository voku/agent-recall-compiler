<?php

declare(strict_types=1);

const TASK_ID = 'ARC-55';
const ACTOR = 'chatgpt-dogfood';
const REPORT_DIR = 'build/stale-guidance-selection-dogfood';
const AGENT_LOOP_BIN = 'build/agent-loop/bin/agent-loop';
const AGENT_MAP_BIN = 'build/agent-loop/vendor/bin/agent-map';

run(['rm', '-rf', '.agent-loop', REPORT_DIR]);
ensureDirectory(REPORT_DIR);

run([PHP_BINARY, AGENT_LOOP_BIN, 'init', 'scaffold']);
@unlink('.agent-loop/todo/cards/DEMO-1.md');
@unlink('.agent-loop/tasks/DEMO-1.md');
file_put_contents(
    '.agent-loop/todo/kanban.config.json',
    json_encode([
        'projectPrefix' => 'ARC',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL,
);

$taskBriefPath = '.agent-loop/tasks/' . TASK_ID . '.md';
file_put_contents(
    $taskBriefPath,
    "# ARC-55\n\nUse the real proposal.2026-08-14.004 and .011 regressions to reject obviously stale selected guidance without rewriting proposal history.\n",
);

run([
    PHP_BINARY, AGENT_LOOP_BIN, 'board', 'card', 'create', TASK_ID,
    '--title=Reject stale selected guidance deterministically',
    '--lane=BACKLOG',
    '--status=Backlog',
    '--summary=Use real approved-guidance regressions to make stale validation entry points fail closed.',
]);
run([
    PHP_BINARY, AGENT_LOOP_BIN, 'board', 'card', 'update', TASK_ID,
    '--status=Selected',
    '--brief=' . $taskBriefPath,
]);
run([
    PHP_BINARY, AGENT_LOOP_BIN, 'board', 'card', 'move', TASK_ID,
    '--to=READY',
    '--actor=' . ACTOR,
]);

run([
    AGENT_MAP_BIN, 'build',
    '--root=.',
    '--paths=src,tests',
    '--out=.agent-loop/map/php-symbols.json',
    '--phpstan-config=phpstan.neon.dist',
]);
run([
    PHP_BINARY, AGENT_LOOP_BIN, 'workflow', 'plan', TASK_ID,
    '--by', ACTOR,
    '--file', 'src/RecallDecisionEngine.php',
    '--file', 'src/Compilation/RecallCompilationService.php',
    '--file', 'tests/FeedbackAndBlockTest.php',
    '--goal', 'Block selected approved guidance when an unambiguous validation command references a missing project-local entry point, reproducing proposal.2026-08-14.004 and proposal.2026-08-14.011.',
    '--non-goal', 'Do not infer staleness from arbitrary missing scope paths, score directive prose, rewrite Proposal history, retire guidance, or add a new lifecycle state.',
    '--behavior-anchor', 'approved guidance -> scope or tag selection -> validation entry-point liveness -> selected context or explicit BLOCKED result',
    '--validation', 'vendor/bin/phpunit tests/FeedbackAndBlockTest.php',
    '--validation', 'vendor/bin/phpstan analyse --configuration=phpstan.neon.dist',
]);
run([PHP_BINARY, AGENT_LOOP_BIN, 'workflow', 'approve', TASK_ID, '--by', 'voku']);
run([
    PHP_BINARY, AGENT_LOOP_BIN, 'workflow', 'context', TASK_ID,
    '--max-lines', '120', '--max-bytes', '12000',
], REPORT_DIR . '/workflow-context.txt');

$systemPath = '.agent-loop/recall/' . TASK_ID . '/system.md';
if (!is_file($systemPath) || !copy($systemPath, REPORT_DIR . '/system.md')) {
    fail('compiled system.md not found or could not be copied');
}

$runData = readJson('.agent-loop/runs/' . TASK_ID . '/run.json');
$contractRevision = $runData['contract_revision'] ?? null;
if (!is_int($contractRevision) || $contractRevision < 1) {
    fail('governed Run has no positive contract_revision');
}

$validations = [
    [
        'command' => 'vendor/bin/phpunit tests/FeedbackAndBlockTest.php',
        'argv' => ['vendor/bin/phpunit', 'tests/FeedbackAndBlockTest.php'],
    ],
    [
        'command' => 'vendor/bin/phpstan analyse --configuration=phpstan.neon.dist',
        'argv' => ['vendor/bin/phpstan', 'analyse', '--configuration=phpstan.neon.dist'],
    ],
];
foreach ($validations as $index => $validation) {
    $started = hrtime(true);
    run($validation['argv'], REPORT_DIR . '/validation-' . ($index + 1) . '.log');
    $durationMs = intdiv(hrtime(true) - $started, 1_000_000);
    run([
        PHP_BINARY, AGENT_LOOP_BIN, 'session', 'validation', 'record', TASK_ID,
        '--contract-revision', (string) $contractRevision,
        '--command', $validation['command'],
        '--status', 'passed',
        '--exit-code', '0',
        '--duration-ms', (string) $durationMs,
        '--by', ACTOR,
    ]);
}

run([
    PHP_BINARY, AGENT_LOOP_BIN, 'review', 'blindspots', TASK_ID,
], REPORT_DIR . '/blindspots.txt');

$reviewPath = '.agent-loop/recall/' . TASK_ID . '/reviews/' . TASK_ID . '.blindspots.json';
if (!is_file($reviewPath) || !copy($reviewPath, REPORT_DIR . '/blindspots.json')) {
    fail('blind-spot review artifact not found');
}
run([
    PHP_BINARY, AGENT_LOOP_BIN, 'session', 'checkpoint', TASK_ID,
    '--title=Review',
    '--body=review blindspots ARC-55 was generated and inspected by the stale-guidance dogfood run.',
]);

run([
    PHP_BINARY, AGENT_LOOP_BIN, 'workflow', 'learn', TASK_ID,
    '--status', 'no_durable_learning',
    '--by', ACTOR,
    '--reason', 'The task implements already-validated .004/.011 regression evidence; this bounded run exposed no additional reusable finding.',
]);
run([PHP_BINARY, AGENT_LOOP_BIN, 'verify', '--task-id=' . TASK_ID], REPORT_DIR . '/verify.txt');
run([
    PHP_BINARY, AGENT_LOOP_BIN, 'workflow', 'report', TASK_ID,
    '--changed-file', 'src/Compilation/RecallCompilationService.php',
    '--changed-file', 'tests/FeedbackAndBlockTest.php',
    '--format', 'json',
], REPORT_DIR . '/workflow-report.json');
run([PHP_BINARY, AGENT_LOOP_BIN, 'workflow', 'close', TASK_ID, '--status', 'done'], REPORT_DIR . '/workflow-close.txt');
run([
    PHP_BINARY, AGENT_LOOP_BIN, 'workflow', 'status', TASK_ID,
    '--expect', 'complete', '--format=json',
], REPORT_DIR . '/workflow-status.json');

file_put_contents(REPORT_DIR . '/result.json', json_encode([
    'schema_version' => '1.0',
    'task_id' => TASK_ID,
    'contract_revision' => $contractRevision,
    'approved_by' => 'voku',
    'validation_commands' => array_column($validations, 'command'),
    'review' => 'blindspots_recorded',
    'learning' => 'no_durable_learning',
    'verification' => 'passed',
    'state' => 'complete',
    'result' => 'passed',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);

fwrite(STDOUT, "ARC-55 agent-loop dogfood: COMPLETE\n");

/** @param non-empty-list<string> $command */
function run(array $command, ?string $outputPath = null): int
{
    $stdout = $outputPath === null ? ['file', 'php://stdout', 'w'] : ['file', $outputPath, 'w'];
    $stderr = $outputPath === null ? ['file', 'php://stderr', 'w'] : ['redirect', 1];
    $process = proc_open($command, [
        0 => ['file', 'php://stdin', 'r'],
        1 => $stdout,
        2 => $stderr,
    ], $pipes);
    if (!is_resource($process)) {
        fail('cannot start command: ' . implode(' ', $command));
    }
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        fail(sprintf('command failed with exit %d: %s', $exitCode, implode(' ', $command)));
    }

    return $exitCode;
}

/** @return array<string, mixed> */
function readJson(string $path): array
{
    $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data)) {
        fail('expected JSON object: ' . $path);
    }

    return $data;
}

function ensureDirectory(string $path): void
{
    if (!is_dir($path) && !mkdir($path, 0o775, true) && !is_dir($path)) {
        fail('cannot create directory: ' . $path);
    }
}

function fail(string $message): never
{
    fwrite(STDERR, '[FAIL] ' . $message . PHP_EOL);
    exit(1);
}
