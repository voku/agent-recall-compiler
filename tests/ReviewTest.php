<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentRecallCompiler\Review\BlindSpotReviewer;
use voku\AgentRecallCompiler\Review\ReviewCli;
use voku\AgentRecallCompiler\Review\ReviewPromptBuilder;
use voku\AgentRecallCompiler\Review\ReviewReportWriter;

/** @internal */
final class ReviewTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-recall-review-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testValidTaskIdMustStartWithAlphanumeric(): void
    {
        self::assertTrue(BlindSpotReviewer::isValidTaskId('ABC-123'));
        self::assertTrue(BlindSpotReviewer::isValidTaskId('A'));
        self::assertTrue(BlindSpotReviewer::isValidTaskId('1'));
        self::assertFalse(BlindSpotReviewer::isValidTaskId(''));
        self::assertFalse(BlindSpotReviewer::isValidTaskId('.'));
        self::assertFalse(BlindSpotReviewer::isValidTaskId('_ABC'));
        self::assertFalse(BlindSpotReviewer::isValidTaskId('a..b'));
        self::assertFalse(BlindSpotReviewer::isValidTaskId('../ABC'));
    }

    public function testMissingRecallArtifactsProducesFail(): void
    {
        $report = (new BlindSpotReviewer($this->root))->review('ABC-123', '.agent-recall/current');

        $findingIds = array_map(static fn ($finding): string => $finding->id, $report->findings);

        self::assertSame('fail', $report->status());
        self::assertContains('missing_recall_meta', $findingIds);
        self::assertContains('missing_validation_plan', $findingIds);
    }

    public function testWriterCreatesReportsAndPrompt(): void
    {
        $this->write('.agent-recall/current/meta.json', '{"task_id":"ABC-123","task_files":["src/Foo.php"]}');
        $this->write('.agent-recall/current/validation-plan.md', 'Run vendor/bin/phpunit.');
        $this->write('.agent-recall/current/recall-log.draft.json', '{"guidance_outcomes":[{"outcome":"unknown"}]}');

        $report = (new BlindSpotReviewer($this->root))->review('ABC-123', '.agent-recall/current');
        (new ReviewReportWriter($this->root))->write($report, '.agent-recall/current');

        self::assertSame('warn', $report->status());

        $json = (string) file_get_contents($this->root . '/.agent-recall/current/reviews/ABC-123.blindspots.json');
        $markdown = (string) file_get_contents($this->root . '/.agent-recall/current/reviews/ABC-123.blindspots.md');

        self::assertFileExists($this->root . '/.agent-recall/current/reviews/ABC-123.blindspots.json');
        self::assertFileExists($this->root . '/.agent-recall/current/reviews/ABC-123.blindspots.md');
        self::assertFileExists($this->root . '/.agent-recall/current/reviews/ABC-123.blindspots.prompt.md');
        self::assertStringContainsString('"status": "warn"', $json);
        self::assertStringContainsString('Status: warn', $markdown);
        self::assertStringContainsString('missing_review_checkpoint', $markdown);
        self::assertStringContainsString('L2 blind-spot analysis prompt for ABC-123', (string) file_get_contents($this->root . '/.agent-recall/current/reviews/ABC-123.blindspots.prompt.md'));
    }

    public function testCodePromptUsesTaskFilesAndUnreadableMetaIsSafe(): void
    {
        $this->write('.agent-recall/current/meta.json', '{"task_id":"ABC-123","task_files":["src/Foo.php","../secret"]}');
        $this->write('src/Foo.php', '<?php echo "ok";');

        $prompt = (new ReviewPromptBuilder($this->root))->buildCodeReviewPrompt('ABC-123', '.agent-recall/current');

        self::assertStringContainsString('src/Foo.php', $prompt);
        self::assertStringNotContainsString('### ../secret', $prompt);

        file_put_contents($this->root . '/.agent-recall/current/meta.json', '{invalid');
        $promptWithMalformedMeta = (new ReviewPromptBuilder($this->root))->buildCodeReviewPrompt('ABC-123', '.agent-recall/current');
        self::assertStringContainsString('L2 code review prompt for ABC-123', $promptWithMalformedMeta);
        self::assertStringNotContainsString('### ../secret', $promptWithMalformedMeta);
    }

    public function testPromptIncludesBoardAndRelatedSessionArtifacts(): void
    {
        $this->write('.agent-recall/current/meta.json', '{"task_id":"ABC-123","task_files":[]}');
        $this->write('.agent-recall/current/validation-plan.md', 'Run composer test.');
        $this->write('todo/cards/ABC-123.md', '# Board card');
        $this->write('session_plan/2026-06-28-work/session.json', '{"task_id":"ABC-123"}');
        $this->write('session_plan/2026-06-28-work/checkpoints/001-validation.md', 'ABC-123 PHPUnit passed review blindspots checked');

        $prompt = (new ReviewPromptBuilder($this->root))->buildBlindSpotPrompt(
            (new BlindSpotReviewer($this->root))->review('ABC-123', '.agent-recall/current'),
            '.agent-recall/current',
        );

        self::assertStringContainsString('todo/cards/ABC-123.md', $prompt);
        self::assertStringContainsString('session_plan/2026-06-28-work/checkpoints/001-validation.md', $prompt);
    }

    public function testReviewCliHelpAndCodeCommand(): void
    {
        $this->write('.agent-recall/current/meta.json', '{"task_id":"ABC-123","task_files":["src/Foo.php"]}');
        $this->write('src/Foo.php', '<?php echo "ok";');

        $help = $this->runReviewCli(['agent-recall-compiler review', 'help']);
        self::assertSame(0, $help['exit']);
        self::assertStringContainsString('agent-recall-compiler review blindspots <task-id>', $help['output']);

        $code = $this->runReviewCli(['agent-recall-compiler review', 'code', 'ABC-123']);
        self::assertSame(0, $code['exit']);
        self::assertStringContainsString('.agent-recall/current/reviews/ABC-123.code.prompt.md', $code['output']);
        self::assertFileExists($this->root . '/.agent-recall/current/reviews/ABC-123.code.prompt.md');

        $invalid = $this->runReviewCli(['agent-recall-compiler review', 'code', '../foo']);
        self::assertSame(1, $invalid['exit']);

        $unknown = $this->runReviewCli(['agent-recall-compiler review', 'wat']);
        self::assertSame(1, $unknown['exit']);

        $blindspots = $this->runReviewCli(['agent-recall-compiler review', 'blindspots', 'ABC-123']);
        self::assertSame(1, $blindspots['exit']);
        self::assertFileExists($this->root . '/.agent-recall/current/reviews/ABC-123.blindspots.json');
        self::assertFileExists($this->root . '/.agent-recall/current/reviews/ABC-123.blindspots.md');
        self::assertFileExists($this->root . '/.agent-recall/current/reviews/ABC-123.blindspots.prompt.md');
    }

    public function testSessionNotesCanSatisfyValidationAndReviewMarkers(): void
    {
        $this->write('.agent-recall/current/meta.json', '{"task_id":"ABC-123","task_files":[]}');
        $this->write('.agent-recall/current/validation-plan.md', 'Required validation');
        $this->write('.agent-recall/current/recall-log.draft.json', '{"guidance_outcomes":[{"outcome":"unknown"}]}');
        $this->write('session_plan/ABC-123.md', 'ABC-123 PHPUnit passed and review blindspots checked.');

        $report = (new BlindSpotReviewer($this->root))->review('ABC-123', '.agent-recall/current');

        self::assertSame('ok', $report->status());
        self::assertNotContains('missing_validation_evidence', array_map(static fn ($finding): string => $finding->id, $report->findings));
        self::assertNotContains('missing_review_checkpoint', array_map(static fn ($finding): string => $finding->id, $report->findings));
    }

    public function testSessionMatchingIsBoundaryAware(): void
    {
        $this->write('.agent-recall/current/meta.json', '{"task_id":"ABC-123","task_files":[]}');
        $this->write('.agent-recall/current/validation-plan.md', 'Required validation');
        $this->write('.agent-recall/current/recall-log.draft.json', '{"guidance_outcomes":[{"outcome":"unknown"}]}');
        $this->write('session_plan/ABC-1234.md', 'ABC-1234 PHPUnit passed and review blindspots checked.');

        $report = (new BlindSpotReviewer($this->root))->review('ABC-123', '.agent-recall/current');
        $findingIds = array_map(static fn ($finding): string => $finding->id, $report->findings);

        self::assertContains('missing_validation_evidence', $findingIds);
        self::assertContains('missing_review_checkpoint', $findingIds);
    }

    public function testPromptFenceExpandsForMarkdownBackticks(): void
    {
        $this->write('.agent-recall/current/meta.json', '{"task_id":"ABC-123","task_files":[]}');
        $this->write('.agent-recall/current/validation-plan.md', "```php\necho 'x';\n```");

        $prompt = (new ReviewPromptBuilder($this->root))->buildCodeReviewPrompt('ABC-123', '.agent-recall/current');

        self::assertStringContainsString('````text', $prompt);
    }


    /**
     * @param list<string> $argv
     *
     * @return array{exit: int, output: string}
     */
    private function runReviewCli(array $argv): array
    {
        ob_start();
        try {
            $exit = (new ReviewCli($this->root))->run($argv);
            $output = (string) ob_get_clean();
        } catch (\Throwable $throwable) {
            ob_end_clean();
            throw $throwable;
        }

        return ['exit' => $exit, 'output' => $output];
    }

    private function write(string $relative, string $content): void
    {
        $path = $this->root . '/' . $relative;
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0o775, true);
        }
        file_put_contents($path, $content);
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
