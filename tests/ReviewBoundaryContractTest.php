<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentRecallCompiler\Review\ReviewCli;

/** @internal */
final class ReviewBoundaryContractTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-recall-review-boundary-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o775, true);
    }

    protected function tearDown(): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    public function testBlindspotSubcommandHelpDistinguishesAuditFromSemanticReview(): void
    {
        $result = $this->runReviewCli(['agent-recall-compiler review', 'blindspots', '--help']);

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('deterministic prerequisite/evidence audit', $result['output']);
        self::assertStringContainsString('does not execute that semantic review', $result['output']);
    }

    public function testBlindspotCommandLabelsDeterministicAuditAndSemanticHandoff(): void
    {
        $result = $this->runReviewCli(['agent-recall-compiler review', 'blindspots', 'ABC-123']);

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('Deterministic blind-spot evidence audit for ABC-123: fail', $result['output']);
        self::assertStringContainsString('Semantic L2 review prompt:', $result['output']);
        self::assertStringContainsString('Semantic review: NOT EXECUTED by this command', $result['output']);

        $markdown = (string) file_get_contents(
            $this->root . '/.agent-recall/current/reviews/ABC-123.blindspots.md',
        );
        self::assertStringContainsString('# Deterministic blind-spot evidence audit for ABC-123', $markdown);
        self::assertStringContainsString('does not mean the semantic L2 blind-spot review has been executed', $markdown);
    }

    /**
     * @param list<string> $argv
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
}
