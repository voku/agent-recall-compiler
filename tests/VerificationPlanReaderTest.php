<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
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
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            is_dir($file) ? rmdir($file) : unlink($file);
        }
        rmdir($this->dir);
    }

    public function testAbsentPlanIsALegitimateEmptyState(): void
    {
        self::assertNull((new VerificationPlanReader())->read($this->dir));
    }

    public function testPlanIsBoundToItsExactBytes(): void
    {
        $raw = (string) json_encode([
            'task_id' => 'ABC-1',
            'target' => 'src/Foo.php',
            'knowledge_probes' => [['id' => 'probe-1'], ['id' => ''], 'not-an-entry'],
            'checklist' => [['id' => 'check-1'], ['id' => 'check-2']],
        ]);
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

    public function testMissingIdentityFieldsStayUnknownInsteadOfInvented(): void
    {
        file_put_contents($this->dir . '/verification-plan.json', '{}');

        $plan = (new VerificationPlanReader())->read($this->dir);

        self::assertNotNull($plan);
        self::assertNull($plan->taskId);
        self::assertNull($plan->target);
        self::assertSame([], $plan->probeIds);
        self::assertSame([], $plan->checklistIds);
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
}
