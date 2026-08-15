<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentRecallCompiler\Review\BlindSpotFinding;
use voku\AgentRecallCompiler\Review\BlindSpotReviewer;

final class BlindSpotMarkerBoundaryTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-recall-marker-boundary-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/.agent-recall/current', 0o775, true);
        file_put_contents($this->root . '/.agent-recall/current/meta.json', '{"task_id":"ABC-123"}');
        file_put_contents($this->root . '/.agent-recall/current/validation-plan.md', 'PHPUnit tests passed.');
        file_put_contents($this->root . '/.agent-recall/current/recall-log.draft.json', '{"outcome":"unknown"}');
        mkdir($this->root . '/.agent-loop/sessions', 0o775, true);
        file_put_contents(
            $this->root . '/.agent-loop/sessions/ABC-123.md',
            'ABC-123 review blindspots checked; PHPUnit tests passed.',
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testEmbeddedSecuritySubstringsDoNotCreateSecurityFinding(): void
    {
        file_put_contents(
            $this->root . '/.agent-recall/current/system.md',
            'Navigation symbols: Request::hasBasicAuth, MysqlConnection, RoleManager.',
        );

        $report = (new BlindSpotReviewer($this->root))->review('ABC-123', '.agent-recall/current');

        self::assertNull($this->finding($report->findings, 'security_sensitive_context'));
    }

    public function testStandaloneSecurityTermsStillMatchCaseInsensitively(): void
    {
        file_put_contents(
            $this->root . '/.agent-recall/current/system.md',
            'Review AUTH handling, SQL boundaries, and the role assignment.',
        );

        $report = (new BlindSpotReviewer($this->root))->review('ABC-123', '.agent-recall/current');
        $finding = $this->finding($report->findings, 'security_sensitive_context');

        self::assertNotNull($finding);
        self::assertSame('warn', $finding->severity->value);
        self::assertSame(['Matched markers: auth, sql, role'], $finding->evidence);
    }

    /**
     * @param list<BlindSpotFinding> $findings
     */
    private function finding(array $findings, string $id): ?BlindSpotFinding
    {
        foreach ($findings as $finding) {
            if ($finding->id === $id) {
                return $finding;
            }
        }

        return null;
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
