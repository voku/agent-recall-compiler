<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Reflection;

use RuntimeException;

/**
 * Renders the L2 prompt a human uses to decide a queue of candidate proposals.
 *
 * Approval is the one gate an agent may not pass. Nothing here approves,
 * rejects or edits anything: it compiles what is deterministically true about
 * each candidate - its sources, its target, and whether that target already
 * contains the rule - and hands the reviewer the exact commands, so the
 * decision is informed rather than transcribed from a summary.
 *
 * The distinction that does the work is `already_applied`. A candidate whose
 * target text is already present was written into its canonical home while the
 * work happened; approving it records a decision about something that exists,
 * and the follow-up is `proposal-mark-applied`. A candidate whose target does
 * not contain it still has to be applied after approval. Presenting both as
 * one undifferentiated "approve?" queue is how a reviewer ends up rubber
 * stamping the half they cannot distinguish.
 */
final readonly class ApprovalQueuePromptBuilder
{
    public function build(string $learningRoot, ?string $memoryPath = null): string
    {
        $candidates = $this->candidates($learningRoot);
        $memory = $this->memory($learningRoot, $memoryPath);

        if ($candidates === []) {
            return "No candidate proposals are waiting. The approval queue is empty; there is nothing to decide.\n";
        }

        $lines = [
            'Decide the candidate proposals below. You are the approver; this prompt is not.',
            '',
            'Each candidate is a durable-guidance change derived from findings that were already',
            'validated and consolidated. Approving one records a reviewed decision; it does not',
            'edit anything by itself. Rejecting one is a first-class outcome and needs a reason',
            'that a later reader can act on.',
            '',
            sprintf('Learning root: %s', $learningRoot),
            sprintf('Candidates: %d', count($candidates)),
            '',
        ];

        foreach ($candidates as $index => $candidate) {
            $lines = [...$lines, ...$this->renderCandidate($index + 1, $candidate, $memory)];
        }

        return implode("\n", [...$lines, ...$this->renderInstructions()]) . "\n";
    }

    /**
     * @param array<string, mixed> $candidate
     * @return list<string>
     */
    private function renderCandidate(int $position, array $candidate, ?string $memory): array
    {
        $id = $this->string($candidate, 'id');
        $target = $this->string($candidate, 'target');
        $applied = $memory !== null && $target !== '' && str_contains($memory, '| ' . $target . ' |');

        $lines = [
            sprintf('## %d. %s', $position, $id),
            '',
            sprintf('- action: %s -> %s', $this->string($candidate, 'action'), $this->string($candidate, 'target_type')),
            sprintf('- target: %s', $target === '' ? '(none)' : $target),
            sprintf('- pattern key: %s', $this->string($candidate, 'pattern_key')),
        ];

        $sources = $candidate['source_findings'] ?? [];
        if (is_array($sources) && $sources !== []) {
            $lines[] = '- derived from: ' . implode(', ', array_filter($sources, 'is_string'));
        }

        if ($memory === null) {
            $lines[] = '- already in its target: unknown (the target document could not be read)';
        } else {
            $lines[] = $applied
                ? '- already in its target: YES - approving records a decision about text that is already there,'
                . ' so follow approval with proposal-mark-applied'
                : '- already in its target: NO - approving does not write it; the target still has to be changed';
        }

        $reason = $this->string($candidate, 'reason');
        if ($reason !== '') {
            $lines = [...$lines, '', '### Why it was proposed', '', $reason];
        }

        $new = $this->string($candidate, 'new');
        if ($new !== '') {
            $lines = [...$lines, '', '### Proposed rule', '', $new];
        }

        $validationCase = $candidate['validation_case'] ?? null;
        if (is_array($validationCase)) {
            $lines = [...$lines, '', '### Validation case', ''];
            foreach (['given', 'when', 'then'] as $key) {
                $value = $validationCase[$key] ?? null;
                if (is_string($value) && $value !== '') {
                    $lines[] = sprintf('- %s: %s', $key, $value);
                }
            }
        }

        $uncertainty = $candidate['remaining_uncertainty'] ?? [];
        if (is_array($uncertainty) && $uncertainty !== []) {
            $lines = [...$lines, '', '### Remaining uncertainty', ''];
            foreach ($uncertainty as $item) {
                if (is_string($item) && $item !== '') {
                    $lines[] = '- ' . $item;
                }
            }
        }

        return [...$lines, '', '### Commands', '', ...$this->renderCommands($id, $applied), ''];
    }

    /** @return list<string> */
    private function renderCommands(string $id, bool $applied): array
    {
        $commands = [
            '```bash',
            sprintf('agent-loop learn proposal-approve --by <your-name> %s', $id),
        ];
        if ($applied) {
            $commands[] = sprintf(
                'agent-loop learn proposal-mark-applied --by <your-name> %s   # the target already carries it',
                $id,
            );
        }
        $commands[] = sprintf('agent-loop learn proposal-reject --by <your-name> --reason "<why>" %s', $id);
        $commands[] = '```';

        return $commands;
    }

    /** @return list<string> */
    private function renderInstructions(): array
    {
        return [
            '## What to return',
            '',
            'For each candidate, state approve, reject, or defer, and why in one sentence.',
            '',
            'Weigh these deliberately:',
            '',
            '- Does the rule describe something that actually happened, or a preference dressed as one?',
            '- Is it already enforced by a test or static rule? Then the memory row should point at that,',
            '  and adding prose that repeats an executable check is the promotion this project refuses.',
            '- Would a reader six months from now be able to act on it without this conversation?',
            '- Does it overlap an existing rule closely enough to merge instead?',
            '',
            'Rejecting is not a failure of the work that produced the finding. A finding can be true and',
            'still not be worth a durable rule; say so plainly rather than approving to be agreeable.',
            '',
            'Do not approve on the agent\'s recommendation alone. The queue exists because this decision',
            'is the one an agent must not make for you.',
        ];
    }

    /** @return list<array<string, mixed>> */
    private function candidates(string $learningRoot): array
    {
        $directory = rtrim($learningRoot, '/') . '/proposals/candidate';
        if (!is_dir($directory)) {
            return [];
        }

        $files = glob($directory . '/*.json');
        if ($files === false) {
            return [];
        }
        sort($files, SORT_STRING);

        $candidates = [];
        foreach ($files as $file) {
            $decoded = json_decode((string) file_get_contents($file), true);
            if (!is_array($decoded)) {
                throw new RuntimeException('Candidate proposal is not valid JSON: ' . $file);
            }
            /** @var array<string, mixed> $decoded */
            $candidates[] = $decoded;
        }

        return $candidates;
    }

    /**
     * The document a memory-targeted proposal would land in.
     *
     * Read-only and best effort: when it cannot be found the prompt says the
     * applied state is unknown rather than guessing, because guessing wrong in
     * either direction changes what the reviewer is being asked to do.
     */
    private function memory(string $learningRoot, ?string $memoryPath): ?string
    {
        $candidates = $memoryPath !== null
            ? [$memoryPath]
            : [
                rtrim($learningRoot, '/') . '/MEMORY.md',
                dirname(rtrim($learningRoot, '/')) . '/MEMORY.md',
                dirname(rtrim($learningRoot, '/'), 2) . '/MEMORY.md',
            ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                $content = file_get_contents($candidate);
                if ($content !== false) {
                    return $content;
                }
            }
        }

        return null;
    }

    /** @param array<string, mixed> $record */
    private function string(array $record, string $key): string
    {
        $value = $record[$key] ?? null;

        return is_string($value) ? $value : '';
    }
}
