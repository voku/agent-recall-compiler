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
        $this->writeTaskFiles([
            'src/AuthenticationController.php',
            'src/Authorization.php',
            'lib/basicOAuth.php',
            'docs/roleplaying.md',
            'assets/scrolling.js',
        ]);

        self::assertFalse($this->hasSecurityFinding());
    }

    public function testSecurityMarkersMatchStandaloneAndSeparatedIdentifierTermsInTaskFiles(): void
    {
        $this->writeTaskFiles(['src/basic_auth.php', 'src/role_id.php', 'db/sql-query.sql', 'web/csrf.token.php', 'web/xss/login.php', 'src/password.php', 'src/permission.php']);

        $finding = $this->securityFinding();
        self::assertNotNull($finding);
        self::assertSame('Matched markers: auth, login, password, csrf, xss, sql, permission, role', $finding->evidence[0]);
    }

    public function testTaskTargetsCountAsWorkTheTaskTouches(): void
    {
        file_put_contents($this->root . '/build/review/meta.json', json_encode(['task_files' => [], 'task_targets' => ['modules/auth/login']], JSON_THROW_ON_ERROR));

        self::assertTrue($this->hasSecurityFinding());
    }

    public function testGuidanceProseInRecallArtifactsDoesNotTriggerTheSecurityWarning(): void
    {
        // system.md embeds the selected guidance, which names these words by construction. Scanning it made the
        // warning fire for 220 of 223 real reports, so it carried no information about the task at hand.
        $this->writeTaskFiles(['docs/notes.md']);
        $guidance = "Rule: every role needs a permission check; use sql bound parameters; never log a password; csrf and xss apply on login and auth.\n";
        file_put_contents($this->root . '/build/review/system.md', $guidance);
        file_put_contents($this->root . '/build/review/recall-log.draft.json', json_encode(['comment' => $guidance], JSON_THROW_ON_ERROR));

        self::assertFalse($this->hasSecurityFinding());
    }

    public function testEvidenceNamesTheMatchingPathsAndCapsThemAtFive(): void
    {
        $this->writeTaskFiles(['src/a/login.php', 'src/b/login.php', 'src/c/login.php', 'src/d/login.php', 'src/e/login.php', 'src/f/login.php', 'src/readme.md']);

        $finding = $this->securityFinding();
        self::assertNotNull($finding);
        self::assertSame('In: src/a/login.php, src/b/login.php, src/c/login.php, src/d/login.php, src/e/login.php', $finding->evidence[1]);
    }

    /**
     * @param list<string> $files
     */
    private function writeTaskFiles(array $files): void
    {
        file_put_contents($this->root . '/build/review/meta.json', json_encode(['task_files' => $files, 'task_targets' => []], JSON_THROW_ON_ERROR));
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
