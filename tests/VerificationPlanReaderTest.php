<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentRecallCompiler\Verification\VerificationPlanDocument;
use voku\AgentRecallCompiler\Verification\VerificationPlanReader;

/** @internal */
final class VerificationPlanReaderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/recall-verification-plan-' . bin2hex(random_bytes(6));
        if (!mkdir($this->dir, 0o775, true) && !is_dir($this->dir)) {
            throw new RuntimeException('Unable to create fixture directory.');
        }
    }

    protected function tearDown(): void
    {
        chmod($this->dir, 0o775);
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            if (is_link($file)) {
                unlink($file);
            } elseif (is_file($file)) {
                chmod($file, 0o664);
                unlink($file);
            } else {
                rmdir($file);
            }
        }
        rmdir($this->dir);
    }

    public function testAbsentPlanIsALegitimateEmptyState(): void
    {
        self::assertNull((new VerificationPlanReader())->read($this->dir));
    }

    public function testPlanIsBoundToItsExactBytes(): void
    {
        $raw = (string) json_encode(self::plan());
        file_put_contents($this->dir . '/verification-plan.json', $raw);

        $plan = (new VerificationPlanReader())->read($this->dir . '/');

        self::assertNotNull($plan);
        self::assertSame($this->dir . '/verification-plan.json', $plan->path);
        self::assertSame('sha256:' . hash('sha256', $raw), $plan->sha256);
        self::assertSame('ABC-1', $plan->taskId);
        self::assertSame('src/Foo.php', $plan->target);
        self::assertSame(['probe-1'], $plan->probeIds);
        self::assertSame(['check-1', 'check-2'], $plan->checklistIds);
        self::assertSame('ABC-1', $plan->plan['task_id']);
    }

    public function testEmptyAnswerSlotListsAreValid(): void
    {
        file_put_contents($this->dir . '/verification-plan.json', (string) json_encode(self::plan(['knowledge_probes' => [], 'checklist' => []])));

        $plan = (new VerificationPlanReader())->read($this->dir);

        self::assertNotNull($plan);
        self::assertSame([], $plan->probeIds);
        self::assertSame([], $plan->checklistIds);
    }

    public function testPlanWithoutAMapFingerprintIsValid(): void
    {
        file_put_contents($this->dir . '/verification-plan.json', (string) json_encode([...self::plan(), 'analysis_fingerprint' => null]));

        self::assertNotNull((new VerificationPlanReader())->read($this->dir));
    }

    public function testMissingOutputDirectoryIsNotAbsence(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('output directory does not exist or cannot be reached');
        (new VerificationPlanReader())->read($this->dir . '/missing/deeper');
    }

    public function testDanglingSymlinkIsNotAbsence(): void
    {
        symlink($this->dir . '/nowhere.json', $this->dir . '/verification-plan.json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('symbolic link whose target cannot be reached');
        (new VerificationPlanReader())->read($this->dir);
    }

    public function testExistingPlanInAnUnsearchableDirectoryIsNotAbsence(): void
    {
        $this->requireUnprivilegedUser();
        file_put_contents($this->dir . '/verification-plan.json', (string) json_encode(self::plan()));
        chmod($this->dir, 0o664);
        clearstatcache();
        self::assertFalse(file_exists($this->dir . '/verification-plan.json'), 'precondition: the plan must be unreachable');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Verification plan exists but cannot be reached');
        (new VerificationPlanReader())->read($this->dir);
    }

    public function testUnlistableDirectoryIsNotAbsence(): void
    {
        $this->requireUnprivilegedUser();
        chmod($this->dir, 0o333);
        clearstatcache();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('output directory cannot be listed');
        (new VerificationPlanReader())->read($this->dir);
    }

    public function testPlanHiddenByOpenBasedirIsNotAbsence(): void
    {
        mkdir($this->dir . '/inside');
        mkdir($this->dir . '/outside');
        file_put_contents($this->dir . '/outside/plan.json', (string) json_encode(self::plan()));
        symlink($this->dir . '/outside/plan.json', $this->dir . '/inside/verification-plan.json');
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        $code = sprintf(
            'require %s; try { var_export((new %s())->read(%s)); } catch (\\RuntimeException $e) { echo "THROWN: ", $e->getMessage(); }',
            var_export($autoload, true),
            VerificationPlanReader::class,
            var_export($this->dir . '/inside', true),
        );
        $command = sprintf(
            '%s -d open_basedir=%s -d display_errors=0 -d log_errors=0 -r %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($this->dir . '/inside/' . PATH_SEPARATOR . dirname(__DIR__) . '/'),
            escapeshellarg($code),
        );

        exec($command, $output, $exit);

        self::assertSame(0, $exit, implode("\n", $output));
        // open_basedir hides the link itself too (is_link() is false), so only the directory listing reveals it.
        self::assertSame('THROWN: Verification plan exists but cannot be reached: ' . $this->dir . '/inside/verification-plan.json', implode("\n", $output));
        unlink($this->dir . '/inside/verification-plan.json');
        rmdir($this->dir . '/inside');
        unlink($this->dir . '/outside/plan.json');
        rmdir($this->dir . '/outside');
    }

    public function testUnreadablePlanIsCorruptNotAbsent(): void
    {
        $this->requireUnprivilegedUser();
        file_put_contents($this->dir . '/verification-plan.json', (string) json_encode(self::plan()));
        chmod($this->dir . '/verification-plan.json', 0o000);
        clearstatcache();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Verification plan is not readable');
        (new VerificationPlanReader())->read($this->dir);
    }

    public function testMalformedPlanIsCorruptNotAbsent(): void
    {
        file_put_contents($this->dir . '/verification-plan.json', '{"knowledge_probes": [');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid verification plan JSON');
        (new VerificationPlanReader())->read($this->dir);
    }

    public function testNonObjectPlanIsCorruptNotAbsent(): void
    {
        file_put_contents($this->dir . '/verification-plan.json', '[]');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must decode to an object');
        (new VerificationPlanReader())->read($this->dir);
    }

    public function testDirectoryInPlaceOfThePlanIsCorruptNotAbsent(): void
    {
        mkdir($this->dir . '/verification-plan.json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not a regular file');
        (new VerificationPlanReader())->read($this->dir);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function structurallyInvalidPlans(): iterable
    {
        yield 'schema missing' => [self::plan(['schema_version' => null]), 'Unsupported verification plan schema_version null'];
        yield 'schema unsupported' => [self::plan(['schema_version' => '2.0']), 'Unsupported verification plan schema_version "2.0"'];
        yield 'only a schema' => [['schema_version' => '1.0'], 'map_digest must be a non-empty string'];
        yield 'task id empty' => [self::plan(['task_id' => '']), 'task_id must be a non-empty string'];
        yield 'target not a string' => [self::plan(['target' => 7]), 'target must be a non-empty string'];
        yield 'probes a string' => [self::plan(['knowledge_probes' => 'kaputt']), 'knowledge_probes must be a JSON array'];
        yield 'probes missing' => [self::plan(['knowledge_probes' => null]), 'knowledge_probes must be a JSON array'];
        yield 'probes an object' => [self::plan(['knowledge_probes' => ['id' => 'probe-1']]), 'knowledge_probes must be a JSON array'];
        yield 'probe entry a string' => [self::plan(['knowledge_probes' => ['not-an-entry']]), 'knowledge_probes[0] must be an object: '];
        yield 'probe id empty' => [self::plan(['knowledge_probes' => [['id' => 'probe-1'], ['id' => '']]]), 'knowledge_probes[1] must be an object'];
        yield 'probe id duplicate' => [self::plan(['knowledge_probes' => [['id' => 'probe-1'], ['id' => 'probe-1']]]), 'knowledge_probes has duplicate id "probe-1"'];
        yield 'checklist a string' => [self::plan(['checklist' => 'kaputt']), 'checklist must be a JSON array'];
        yield 'checklist id not a string' => [self::plan(['checklist' => [['id' => 3]]]), 'checklist[0] must be an object'];
        yield 'checklist id duplicate' => [self::plan(['checklist' => [['id' => 'c'], ['id' => 'c']]]), 'checklist has duplicate id "c"'];
        yield 'objective gates a string' => [self::plan(['objective_gates' => 'kaputt']), 'objective_gates must be a JSON array'];
        yield 'objective gate without id' => [self::plan(['objective_gates' => [['kind' => 'x']]]), 'objective_gates[0] must be an object with a non-empty string id'];
        yield 'map digest missing' => [self::plan(['map_digest' => null]), 'map_digest must be a non-empty string'];
        yield 'fingerprint missing' => [self::plan(['analysis_fingerprint' => null]), 'analysis_fingerprint must be an object or null'];
        yield 'fingerprint a string' => [self::plan(['analysis_fingerprint' => 'x']), 'analysis_fingerprint must be an object or null'];
        yield 'evidence types empty' => [self::plan(['required_evidence_types' => []]), 'required_evidence_types must be a non-empty JSON array'];
        yield 'evidence type empty' => [self::plan(['required_evidence_types' => ['']]), 'required_evidence_types must contain only non-empty strings'];
        yield 'generator missing' => [self::plan(['generator' => null]), 'generator must be an object'];
        yield 'generator seed missing' => [self::plan(['generator' => ['name' => 'n', 'version' => '1']]), 'generator.seed_sha256 must be a string'];
        yield 'omitted candidates a string' => [self::plan(['omitted_probe_candidates' => 'x']), 'omitted_probe_candidates must be a JSON array'];
        yield 'omitted candidate not an object' => [self::plan(['omitted_probe_candidates' => [1]]), 'omitted_probe_candidates[0] must be an object'];
    }

    /** @param array<string, mixed> $plan */
    #[DataProvider('structurallyInvalidPlans')]
    public function testStructurallyInvalidPlanIsCorruptNotEmpty(array $plan, string $message): void
    {
        // null marks a key to drop; analysis_fingerprint null is valid, so dropping it tests a missing key.
        $plan = array_filter($plan, static fn (mixed $value): bool => $value !== null);
        file_put_contents($this->dir . '/verification-plan.json', (string) json_encode($plan));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);
        (new VerificationPlanReader())->read($this->dir);
    }

    /** @return iterable<string, array{list<mixed>, string}> */
    public static function invalidDocumentArguments(): iterable
    {
        $sha = 'sha256:' . str_repeat('a', 64);
        yield 'empty path' => [['', $sha, 'T-1', 'src/Foo.php', [], []], 'path must be a non-empty string'];
        yield 'bad sha256' => [['/p', 'sha256:xyz', 'T-1', 'src/Foo.php', [], []], 'sha256 must be'];
        yield 'empty task id' => [['/p', $sha, '', 'src/Foo.php', [], []], 'taskId must be a non-empty string'];
        yield 'empty target' => [['/p', $sha, 'T-1', '', [], []], 'target must be a non-empty string'];
        yield 'probe id not a string' => [['/p', $sha, 'T-1', 'src/Foo.php', [42], []], 'probeIds must contain only non-empty strings'];
        yield 'empty checklist id' => [['/p', $sha, 'T-1', 'src/Foo.php', [], ['']], 'checklistIds must contain only non-empty strings'];
        yield 'duplicate probe id' => [['/p', $sha, 'T-1', 'src/Foo.php', ['a', 'a'], []], 'probeIds must not contain duplicate ids'];
        yield 'ids not a list' => [['/p', $sha, 'T-1', 'src/Foo.php', [1 => 'a'], []], 'probeIds must be a list'];
    }

    /** @param list<mixed> $arguments */
    #[DataProvider('invalidDocumentArguments')]
    public function testDirectConstructionIsValidatedLikeTheReader(array $arguments, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        new VerificationPlanDocument(...[...$arguments, []]);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function plan(array $overrides = []): array
    {
        return array_replace([
            'schema_version' => '1.0',
            'task_id' => 'ABC-1',
            'target' => 'src/Foo.php',
            'knowledge_probes' => [['id' => 'probe-1']],
            'checklist' => [['id' => 'check-1'], ['id' => 'check-2']],
            'map_digest' => 'sha256:' . str_repeat('b', 64),
            'analysis_fingerprint' => ['tool' => 'agent-map'],
            'omitted_probe_candidates' => [],
            'objective_gates' => [['id' => 'gate:target-resolvable']],
            'required_evidence_types' => ['source_location'],
            'generator' => ['name' => 'agent-recall-compiler', 'version' => '1', 'seed_sha256' => str_repeat('c', 64)],
        ], $overrides);
    }

    private function requireUnprivilegedUser(): void
    {
        if (!function_exists('posix_geteuid')) {
            self::markTestSkipped('ext-posix is required to tell whether this process can observe permission denials.');
        }
        if (posix_geteuid() === 0) {
            self::markTestSkipped('Root bypasses directory and file permissions, so this case is only observable as an unprivileged user.');
        }
    }
}
