<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use voku\AgentLearning\FindingCreator;
use voku\AgentLearning\LearningClassification;
use voku\AgentLearning\LearningLineageService;
use voku\AgentLearning\LearningNote;
use voku\AgentLearning\LearningNoteContent;
use voku\AgentLearning\LearningNoteRepository;
use voku\AgentLearning\LearningNoteRepositoryEvidence;
use voku\AgentLearning\LearningNoteStatus;
use voku\AgentLearning\ValidationCase;
use voku\AgentRecallCompiler\CompileRequest;
use voku\AgentRecallCompiler\InlineCompileTask;
use voku\AgentRecallCompiler\RecallCompiler;

require dirname(__DIR__) . '/vendor/autoload.php';

$records = records($argv);
if (!in_array($records, [100, 500, 1000], true)) {
    fwrite(STDERR, "--records must be one of 100, 500, 1000\n");
    exit(2);
}

$learningVersion = InstalledVersions::getPrettyVersion('voku/agent-learning');
if ($learningVersion !== '0.18.3') {
    fwrite(STDERR, 'Expected released voku/agent-learning 0.18.3, got ' . var_export($learningVersion, true) . "\n");
    exit(2);
}

$projectRoot = sys_get_temp_dir() . '/recall-learning-scale-152-' . $records . '-' . bin2hex(random_bytes(4));
$learningRoot = $projectRoot . '/.agent-loop/learning';
$outputRoot = $projectRoot . '/.agent-loop/recall/scale-152';
$sourcePath = $projectRoot . '/src/Unit1.php';

try {
    mkdir($projectRoot . '/src', 0o775, true);
    file_put_contents($sourcePath, "<?php\n\ndeclare(strict_types=1);\n\nfinal class Unit1 {}\n");
    $sourceHash = hash_file('sha256', $sourcePath);
    if (!is_string($sourceHash)) {
        throw new RuntimeException('Unable to hash scale source fixture.');
    }

    $creator = new FindingCreator();
    $repository = new LearningNoteRepository();
    $validationCase = new ValidationCase(
        given: 'A later task reaches a solved owner boundary.',
        when: 'Recall compiles bounded task context.',
        then: 'Only bounded owner-selected precedent candidates are considered.',
    );

    for ($i = 1; $i <= $records; ++$i) {
        $suffix = sprintf('%06x', $i);
        $findingId = 'finding.2026-09-09.' . $suffix;
        $noteId = 'learning-note.2026-09-09.' . $suffix;
        $patternKey = 'scale.precedent.' . $suffix;
        $relevant = $i === 1;

        $finding = $creator->createValidated(
            root: $learningRoot,
            taskId: $relevant ? 'SCALE-152' : 'OTHER-152',
            session: 'session:scale-152',
            createdBy: 'scale-evidence',
            scope: $relevant ? [] : ['unrelated/'],
            observation: 'A deterministic synthetic precedent record exists for bounded scale evidence.',
            evidence: [[
                'type' => 'manual_verification',
                'summary' => 'Synthetic scale fixture created through the Learning owner Finding API.',
            ]],
            hypothesis: 'Bounded owner acquisition should not grow projection work with the full corpus.',
            validatedConclusion: 'Use only the owner-selected bounded precedent set during Recall compilation.',
            confidence: 'high',
            sensitivity: 'public',
            id: $findingId,
            classification: LearningClassification::ADD_LEARNING_NOTE,
            patternKey: $patternKey,
            validationCase: $validationCase,
        );

        $repository->publish($learningRoot, new LearningNote(
            id: $noteId,
            patternKey: $patternKey,
            status: LearningNoteStatus::ACTIVE,
            scope: $relevant ? [] : ['unrelated/'],
            tags: ['bounded-precedent'],
            sourceFindings: [$finding->finding->id],
            sourceProposals: [],
            validationCase: $validationCase,
            repositoryEvidence: $relevant
                ? [new LearningNoteRepositoryEvidence('src/Unit1.php', $sourceHash)]
                : [],
            content: new LearningNoteContent(
                title: 'Bounded precedent scale fixture ' . $suffix,
                context: 'Large Learning roots must stay cheap to query.',
                guidance: 'Use the bounded Learning owner projection before Recall ranking.',
                whyItWorks: 'The owner selects only candidates that fit inside the explicit result bound.',
                whenToApply: 'When compiling task-local precedent context.',
                whenNotToApply: 'When rebuilding the complete derived Learning graph.',
                verification: 'Compare bounded query and full Recall compile results across corpus sizes.',
            ),
            createdAt: '2026-09-09T00:00:00+00:00',
            updatedAt: '2026-09-09T00:00:00+00:00',
        ));
    }

    $lineage = new LearningLineageService();
    $started = hrtime(true);
    $lineage->rebuild($learningRoot, $projectRoot);
    $rebuildMs = elapsedMs($started);

    $queryTimes = [];
    $queryPeaks = [];
    $queryDigests = [];
    $selection = null;
    for ($run = 0; $run < 3; ++$run) {
        memory_reset_peak_usage();
        $started = hrtime(true);
        $selection = $lineage->precedentsForTask(
            $learningRoot,
            'SCALE-152',
            projectRoot: $projectRoot,
            maximumRelatedIdentities: 100,
        );
        $queryTimes[] = elapsedMs($started);
        $queryPeaks[] = memory_get_peak_usage(true);
        $queryDigests[] = hash('sha256', json_encode($selection->toArray(), JSON_THROW_ON_ERROR));
    }

    if ($selection === null) {
        throw new RuntimeException('Scale query produced no selection.');
    }

    $expectedTruncated = $records > 100;
    requireEvidence(count($selection->precedents) === 100, 'Learning owner did not return exactly 100 bounded precedents.');
    requireEvidence($selection->precedentsTruncated === $expectedTruncated, 'precedents_truncated does not match corpus size.');
    requireEvidence($selection->lineage->truncated === false, 'lineage traversal unexpectedly truncated.');
    requireEvidence(count(array_unique($queryDigests)) === 1, 'Repeated Learning owner queries are not deterministic.');

    $compileTimes = [];
    $compilePeaks = [];
    $bundleDigests = [];
    $promptBytes = [];
    $observations = [];

    for ($run = 0; $run < 3; ++$run) {
        removeDirectory($outputRoot);
        memory_reset_peak_usage();
        $started = hrtime(true);
        $result = (new RecallCompiler())->compile(new CompileRequest(
            learningRoot: $learningRoot,
            taskBrief: null,
            outputDirectory: $outputRoot,
            compilationId: 'scale.SCALE-152',
            inlineTask: new InlineCompileTask(
                taskId: 'SCALE-152',
                description: 'Change the target unit through the bounded owner path.',
                targets: ['src/Unit1.php'],
            ),
        ));
        $compileTimes[] = elapsedMs($started);
        $compilePeaks[] = memory_get_peak_usage(true);
        $bundleDigests[] = $result->bundleSha256;

        $system = file_get_contents($result->systemPath());
        if (!is_string($system)) {
            throw new RuntimeException('Unable to read compiled system prompt.');
        }
        $promptBytes[] = strlen($system);

        $factsContent = file_get_contents($result->factsPath());
        if (!is_string($factsContent)) {
            throw new RuntimeException('Unable to read compiled facts.');
        }
        $facts = json_decode($factsContent, true, flags: JSON_THROW_ON_ERROR);
        $observation = findObservation($facts);
        if ($observation === null) {
            throw new RuntimeException('Compiled facts do not contain learning_precedent_observation.');
        }
        $observations[] = $observation;
    }

    $observation = $observations[0];
    requireEvidence(($observation['candidates_returned'] ?? null) === 100, 'Recall did not receive exactly 100 bounded candidates.');
    requireEvidence(($observation['candidates_considered'] ?? null) === 1, 'Recall considered more than the one deliberately relevant precedent.');
    requireEvidence(($observation['precedents_selected'] ?? null) === 1, 'Recall did not select exactly one precedent.');
    requireEvidence(($observation['selected_precedent_ids'] ?? null) === ['learning-note.2026-09-09.000001'], 'Recall selected an unexpected precedent.');
    requireEvidence(($observation['truncated'] ?? null) === false, 'Recall lost the non-truncated lineage observation.');
    requireEvidence(($observation['precedents_truncated'] ?? null) === $expectedTruncated, 'Recall lost the owner precedent truncation signal.');
    requireEvidence(count(array_unique($bundleDigests)) === 1, 'Repeated full Recall compiles are not deterministic.');
    requireEvidence(count(array_unique($promptBytes)) === 1, 'Repeated full Recall compiles changed prompt size.');
    requireEvidence(count(array_unique(array_map(static fn (array $value): string => hash('sha256', json_encode($value, JSON_THROW_ON_ERROR)), $observations))) === 1, 'Repeated Recall observations are not deterministic.');

    $report = [
        'records' => $records,
        'installed_learning_version' => $learningVersion,
        'installed_learning_reference' => InstalledVersions::getReference('voku/agent-learning'),
        'rebuild_ms' => round($rebuildMs, 3),
        'query_ms' => rounded($queryTimes),
        'query_median_ms' => round(median($queryTimes), 3),
        'query_peak_bytes' => max($queryPeaks),
        'query_deterministic' => count(array_unique($queryDigests)) === 1,
        'lineage_identities' => count($selection->lineage->identities),
        'relations_traversed' => count($selection->lineage->relations),
        'candidates_returned' => count($selection->precedents),
        'lineage_truncated' => $selection->lineage->truncated,
        'precedents_truncated' => $selection->precedentsTruncated,
        'compile_ms' => rounded($compileTimes),
        'compile_median_ms' => round(median($compileTimes), 3),
        'compile_peak_bytes' => max($compilePeaks),
        'prompt_bytes' => $promptBytes[0],
        'candidates_considered' => $observation['candidates_considered'],
        'precedents_selected' => $observation['precedents_selected'],
        'selected_precedent_ids' => $observation['selected_precedent_ids'],
        'compile_deterministic' => count(array_unique($bundleDigests)) === 1,
        'observation_deterministic' => count(array_unique(array_map(static fn (array $value): string => hash('sha256', json_encode($value, JSON_THROW_ON_ERROR)), $observations))) === 1,
    ];

    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
} finally {
    removeDirectory($projectRoot);
}

/** @param list<string> $argv */
function records(array $argv): int
{
    foreach ($argv as $argument) {
        if (str_starts_with($argument, '--records=')) {
            return (int) substr($argument, strlen('--records='));
        }
    }

    return 0;
}

function elapsedMs(int $started): float
{
    return (hrtime(true) - $started) / 1_000_000;
}

/** @param list<float> $values */
function median(array $values): float
{
    sort($values, SORT_NUMERIC);
    return $values[intdiv(count($values), 2)];
}

/** @param list<float> $values
 * @return list<float>
 */
function rounded(array $values): array
{
    return array_map(static fn (float $value): float => round($value, 3), $values);
}

function requireEvidence(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return array<string, mixed>|null */
function findObservation(mixed $value): ?array
{
    if (!is_array($value)) {
        return null;
    }
    if (($value['type'] ?? null) === 'learning_precedent_observation' && is_array($value['payload'] ?? null)) {
        /** @var array<string, mixed> $payload */
        $payload = $value['payload'];
        return $payload;
    }
    foreach ($value as $child) {
        $found = findObservation($child);
        if ($found !== null) {
            return $found;
        }
    }
    return null;
}

function removeDirectory(string $root): void
{
    if (!is_dir($root)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $item) {
        if ($item->isDir()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }
    rmdir($root);
}
