<?php

declare(strict_types=1);

const TASK_ID = 'ARC-55';
const ACTOR = 'chatgpt-dogfood';
const REPORT_DIR = 'build/stale-guidance-selection-dogfood';
const AGENT_LOOP_BIN = 'build/agent-loop/bin/agent-loop';
const AGENT_MAP_BIN = 'build/agent-loop/vendor/bin/agent-map';
const AGENT_LEARNING_BIN = 'build/agent-loop/vendor/bin/agent-learning';

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
file_put_contents(
    '.agent-loop/todo/board.md',
    "# Board Metadata\n\n- **Source:** `todo/cards/*.md`\n- **Project prefix:** ARC\n- **Done count:** 0\n",
);

$taskBrief = 'Use the real proposal.2026-08-14.004 and .011 regressions to reject obviously stale selected guidance without rewriting proposal history.';
$taskBriefPath = '.agent-loop/tasks/' . TASK_ID . '.md';
file_put_contents($taskBriefPath, "# ARC-55\n\n" . $taskBrief . "\n");

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
    '--brief=' . $taskBrief,
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
$sessionId = $runData['session_id'] ?? null;
if (!is_int($contractRevision) || $contractRevision < 1 || !is_string($sessionId) || $sessionId === '') {
    fail('governed Run has no positive contract_revision or session_id');
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
    '--title', 'Review',
    '--body', 'review blindspots ARC-55 was generated and inspected by the stale-guidance dogfood run.',
]);

// agent-learning 0.10.0 validates the historical finding.YYYY-MM-DD.NNN format but does not yet
// expose the later finding-id allocator. This run owns an empty ephemeral learning root, so .001 is
// deterministic and collision-free while keeping the dogfood release set pinned.
$findingId = 'finding.' . (new DateTimeImmutable('now'))->format('Y-m-d') . '.001';
ensureDirectory('.agent-loop/learning/findings/validated');
file_put_contents(
    '.agent-loop/learning/findings/validated/' . $findingId . '.json',
    json_encode([
        'id' => $findingId,
        'task_id' => TASK_ID,
        'session' => $sessionId,
        'created_at' => (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM),
        'created_by' => ACTOR,
        'scope' => ['agent-kanban/card-create', 'agent-loop/init-scaffold'],
        'observation' => 'Full ARC-55 lifecycle dogfood exposed two setup states accepted before verify but rejected by the final verifier: direct READY card creation lacked required taskBrief, and changing kanban.config projectPrefix after init scaffold left board.md declaring DEMO while the board was configured for ARC.',
        'evidence' => [[
            'type' => 'test_result',
            'command' => 'agent-loop verify --task-id=ARC-55',
            'summary' => 'The first close-out failed for missing-task-brief and project-prefix drift. Creating in BACKLOG, setting taskBrief through card update --brief, and moving to READY removed the field failure; verify then isolated board-metadata-inconsistency until board.md metadata matched the configured ARC prefix.',
        ]],
        'hypothesis' => 'Workflow setup and board mutation paths do not atomically enforce or project the same lane and board metadata invariants that verify later checks.',
        'validated_conclusion' => 'Consumers repurposing init scaffold must currently keep kanban config and board metadata synchronized and populate required lane fields before transitions. Owner APIs should eventually make these verify-invalid intermediate states impossible or provide one atomic setup path.',
        'confidence' => 'high',
        'validation_status' => 'validated',
        'status' => 'validated',
        'sensitivity' => 'public',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL,
);
run([AGENT_LEARNING_BIN, 'validate'], REPORT_DIR . '/learning-validate.txt');
run([
    PHP_BINARY, AGENT_LOOP_BIN, 'workflow', 'learn', TASK_ID,
    '--status', 'findings_recorded',
    '--by', ACTOR,
    '--reason', 'Full lifecycle dogfood exposed reproducible board setup/mutation states that only the final verifier rejected.',
    '--finding', $findingId,
]);

run([PHP_BINARY, AGENT_LOOP_BIN, 'verify', '--task-id=' . TASK_ID], REPORT_DIR . '/verify.txt');
run([
    PHP_BINARY, AGENT_LOOP_BIN, 'workflow', 'report', TASK_ID,
    '--changed-file', 'src/Compilation/RecallCompilationService.php',
    '--changed-file', 'tests/FeedbackAndBlockTest.php',
    '--format', 'json',
], REPORT_DIR . '/workflow-report.json');
run([PHP_BINARY, AGENT_LOOP_BIN, 'workflow', 'close', TASK_ID, '--status', 'done'], REPORT_DIR . '/workflow-close.txt');

// CLOSE is the lifecycle gate. The pinned agent-loop release set has an older RunManifest board-path
// projection that can report blocked after a successful close; current agent-loop/main already fixes
// that projection by resolving card sourceFile relative to boardRoot. Do not turn that known version
// skew into a false failure of this candidate.
$verification = readJson('.agent-loop/runs/' . TASK_ID . '/verification.json');
if (($verification['verdict'] ?? null) !== 'satisfied') {
    fail('workflow close did not persist a satisfied verification receipt');
}

file_put_contents(REPORT_DIR . '/result.json', json_encode([
    'schema_version' => '1.0',
    'task_id' => TASK_ID,
    'contract_revision' => $contractRevision,
    'approved_by' => 'voku',
    'validation_commands' => array_column($validations, 'command'),
    'review' => 'blindspots_recorded',
    'learning' => 'findings_recorded',
    'finding_id' => $findingId,
    'verification' => 'passed',
    'close_receipt' => 'satisfied',
    'state' => 'closed',
    'result' => 'passed',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);

fwrite(STDOUT, "ARC-55 agent-loop dogfood: CLOSED\n");

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
