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
ensureDirectory('.agent-loop/tasks');
file_put_contents(
    '.agent-loop/tasks/' . TASK_ID . '.md',
    "# ARC-55\n\nUse the real proposal.2026-08-14.004 and .011 regressions to reject obviously stale selected guidance without rewriting proposal history.\n",
);

run([
    PHP_BINARY, AGENT_LOOP_BIN, 'board', 'card', 'create', TASK_ID,
    '--title=Reject stale selected guidance deterministically',
    '--lane=READY',
    '--status=Selected',
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
if (!is_file($systemPath)) {
    fail('compiled system.md not found');
}
if (!copy($systemPath, REPORT_DIR . '/system.md')) {
    fail('cannot copy compiled system.md');
}

$status = run([
    PHP_BINARY, AGENT_LOOP_BIN, 'workflow', 'status', TASK_ID, '--format=json',
], REPORT_DIR . '/workflow-status.json');
if ($status !== 0) {
    fail('workflow status failed');
}

file_put_contents(REPORT_DIR . '/result.json', json_encode([
    'schema_version' => '1.0',
    'task_id' => TASK_ID,
    'phase' => 'CONTEXT',
    'approved_by' => 'voku',
    'product_mutation_performed' => false,
    'result' => 'ready_for_implementation',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);

fwrite(STDOUT, "ARC-55 agent-loop PLAN/APPROVE/CONTEXT dogfood: PASSED\n");

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
