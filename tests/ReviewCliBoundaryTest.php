<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentRecallCompiler\Review\ReviewCli;

final class ReviewCliBoundaryTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir() . '/agent-recall-review-cli-' . bin2hex(random_bytes(6));
        mkdir($this->workspace, 0o775, true);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->workspace)) {
            return;
        }

        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->workspace, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->workspace);
    }

    public function testHelpSeparatesDeterministicAuditFromSemanticReview(): void
    {
        ob_start();
        $exit = (new ReviewCli($this->workspace))->run(['review', 'help']);
        $output = (string) ob_get_clean();

        self::assertSame(0, $exit);
        self::assertStringContainsString('deterministic prerequisite/evidence audit', $output);
        self::assertStringContainsString('semantic L2 blind-spot review prompt', $output);
        self::assertStringContainsString('still needs host/model execution', $output);
    }

    public function testBlindspotOutputDoesNotPresentAuditAsCompletedSemanticReview(): void
    {
        ob_start();
        $exit = (new ReviewCli($this->workspace))->run(['review', 'blindspots', 'DOGFOOD-1']);
        $output = (string) ob_get_clean();

        self::assertSame(1, $exit);
        self::assertStringContainsString('Deterministic blind-spot audit for DOGFOOD-1: fail', $output);
        self::assertStringContainsString('Markdown audit report:', $output);
        self::assertStringContainsString('JSON audit report:', $output);
        self::assertStringContainsString('Semantic L2 review prompt:', $output);
        self::assertStringContainsString('Deterministic findings:', $output);
    }
}
