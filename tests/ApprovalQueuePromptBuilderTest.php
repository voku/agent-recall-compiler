<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentRecallCompiler\Reflection\ApprovalQueuePromptBuilder;

/**
 * The approval queue is the one gate an agent may not pass, so the prompt's job
 * is to make the decision informed rather than to make it.
 *
 * @internal
 */
final class ApprovalQueuePromptBuilderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/recall-approval-' . bin2hex(random_bytes(5));
        mkdir($this->root . '/proposals/candidate', 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->rm($this->root);
    }

    public function testAnEmptyQueueSaysSoInsteadOfRenderingAnEmptyForm(): void
    {
        self::assertStringContainsString('approval queue is empty', (new ApprovalQueuePromptBuilder())->build($this->root));
    }

    public function testACandidateAlreadyInItsTargetIsDistinguishedFromOneThatIsNot(): void
    {
        $this->candidate('proposal.2026-08-14.001', 'Derived state paths');
        $this->candidate('proposal.2026-08-14.002', 'Something not written yet');
        $memory = $this->root . '/MEMORY.md';
        file_put_contents($memory, "| Subject | Rule | Home |\n| Derived state paths | one owner | src |\n");

        $prompt = (new ApprovalQueuePromptBuilder())->build($this->root, $memory);

        // The distinction is the point: one needs marking applied, the other
        // still has to be written after approval.
        self::assertMatchesRegularExpression('/Derived state paths.*already in its target: YES/s', $prompt);
        self::assertMatchesRegularExpression('/Something not written yet.*already in its target: NO/s', $prompt);
        self::assertStringContainsString('proposal-mark-applied --by <your-name> proposal.2026-08-14.001', $prompt);
        self::assertStringNotContainsString('proposal-mark-applied --by <your-name> proposal.2026-08-14.002', $prompt);
    }

    public function testAnUnreadableTargetReportsUnknownRatherThanGuessing(): void
    {
        $this->candidate('proposal.2026-08-14.001', 'Derived state paths');

        $prompt = (new ApprovalQueuePromptBuilder())->build($this->root, $this->root . '/absent.md');

        self::assertStringContainsString('already in its target: unknown', $prompt);
    }

    public function testTheDecisionIsNotDelegatedToTheAgent(): void
    {
        $this->candidate('proposal.2026-08-14.001', 'Derived state paths');

        $prompt = (new ApprovalQueuePromptBuilder())->build($this->root);

        self::assertStringContainsString('You are the approver; this prompt is not.', $prompt);
        self::assertStringContainsString('Do not approve on the agent\'s recommendation alone.', $prompt);
        self::assertStringContainsString('Rejecting is not a failure', $prompt);
    }

    public function testEveryCandidateOffersApproveAndRejectCommands(): void
    {
        $this->candidate('proposal.2026-08-14.001', 'Derived state paths');

        $prompt = (new ApprovalQueuePromptBuilder())->build($this->root);

        self::assertStringContainsString('proposal-approve --by <your-name> proposal.2026-08-14.001', $prompt);
        self::assertStringContainsString('proposal-reject --by <your-name> --reason "<why>" proposal.2026-08-14.001', $prompt);
    }

    private function candidate(string $id, string $target): void
    {
        file_put_contents(
            $this->root . '/proposals/candidate/' . $id . '.json',
            json_encode([
                'id' => $id,
                'action' => 'ADD',
                'target_type' => 'memory',
                'target' => $target,
                'pattern_key' => 'some.pattern.key',
                'source_findings' => ['finding.2026-08-14.001'],
                'reason' => 'Because a real failure happened.',
                'new' => 'The rule text.',
                'validation_case' => ['given' => 'g', 'when' => 'w', 'then' => 't'],
                'remaining_uncertainty' => ['One open question.'],
            ], JSON_THROW_ON_ERROR),
        );
    }

    private function rm(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->rm($path . '/' . $entry);
            }
        }
        rmdir($path);
    }
}
