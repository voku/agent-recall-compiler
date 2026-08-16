<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;
use voku\AgentRecallCompiler\Review\BlindSpotFinding;
use voku\AgentRecallCompiler\Review\BlindSpotReviewer;

final class BlindSpotSecurityMarkerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-recall-security-marker-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/build/review', 0o775, true);
        file_put_contents($this->root . '/build/review/meta.json', "{}\n");
        file_put_contents($this->root . '/build/review/validation-plan.md', "composer test\n");
    }

    #[After]
    public function cleanup(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testSecurityMarkersDoNotMatchInsideLargerWordsOrIdentifiers(): void
    {
        file_put_contents(
            $this->root . '/build/review/system.md',
            "authentication authorization basicOAuth roleplaying controller scrolling\n",
        );

        self::assertFalse($this->hasSecurityFinding());
    }

    public function testSecurityMarkersMatchStandaloneAndSeparatedIdentifierTerms(): void
    {
        file_put_contents(
            $this->root . '/build/review/system.md',
            "basic_auth role_id sql-query csrf.token xss/login password permission\n",
        );

        $finding = $this->securityFinding();
        self::assertNotNull($finding);
        self::assertSame(
            ['Matched markers: auth, login, password, csrf, xss, sql, permission, role'],
            $finding->evidence,
        );
    }

    private function hasSecurityFinding(): bool
    {
        return $this->securityFinding() !== null;
    }

    private function securityFinding(): ?BlindSpotFinding
    {
        $report = (new BlindSpotReviewer($this->root))->review('TASK-1', $this->root . '/build/review');
        foreach ($report->findings as $finding) {
            if ($finding->id === 'security_sensitive_context') {
                return $finding;
            }
        }

        return null;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = scandir($directory);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $directory . '/' . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }
}
