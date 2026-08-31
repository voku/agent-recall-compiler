<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Review;

use RuntimeException;

final class ReviewCli
{
    public function __construct(private readonly string $workspacePath) {}

    /** @param list<string> $argv */
    public function run(array $argv): int
    {
        $tokens = $argv;
        array_shift($tokens);
        $command = array_shift($tokens) ?? 'help';

        if (in_array($command, ['help', '--help', '-h', ''], true)) {
            echo $this->usage();
            return 0;
        }

        if (!in_array($command, ['blindspots', 'code', 'first-draft'], true)) {
            return $this->unknownCommand($command);
        }

        if (count($tokens) === 1 && in_array($tokens[0], ['--help', '-h'], true)) {
            echo $this->usage();
            return 0;
        }

        if ($command === 'first-draft') {
            if ($tokens !== []) {
                fwrite(\STDERR, "[ERROR] review first-draft accepts no arguments or options.\n");
                return 1;
            }

            echo (new FirstDraftReviewPromptBuilder())->build() . "\n";
            return 0;
        }

        $parsed = $this->parseOptions($tokens);
        $taskId = $parsed['arguments'][0] ?? '';
        $outputDir = $this->stringOption($parsed['options'], 'output-dir');
        if ($outputDir === null || trim($outputDir) === '') {
            $outputDir = '.agent-recall/current';
        }

        if (!BlindSpotReviewer::isValidTaskId($taskId)) {
            fwrite(\STDERR, "[ERROR] Invalid or missing task id. Use an alphanumeric first character followed by letters, numbers, dots, underscores, or hyphens.\n");
            return 1;
        }

        try {
            return match ($command) {
                'blindspots' => $this->runBlindspots(
                    $taskId,
                    $outputDir,
                    $this->contractRevision($parsed['options']),
                    $this->implementationSnapshot($parsed['options']),
                ),
                'code' => $this->runCode($taskId, $outputDir),
            };
        } catch (RuntimeException $exception) {
            fwrite(\STDERR, '[ERROR] ' . $exception->getMessage() . "\n");
            return 1;
        }
    }

    private function runBlindspots(
        string $taskId,
        string $outputDir,
        ?int $contractRevision,
        ?string $implementationSnapshot,
    ): int {
        if (($contractRevision === null) !== ($implementationSnapshot === null)) {
            throw new RuntimeException('--contract-revision and --implementation-snapshot must be provided together.');
        }

        $report = (new BlindSpotReviewer($this->workspacePath))->review($taskId, $outputDir);
        if ($contractRevision !== null) {
            $report = new ReviewReport($report->taskId, $report->findings, $contractRevision, $implementationSnapshot);
        }
        (new ReviewReportWriter($this->workspacePath))->write($report, $outputDir);

        $base = rtrim($outputDir, '/') . '/reviews/' . $taskId . '.blindspots';
        echo 'Deterministic blind-spot evidence audit for ' . $taskId . ': ' . $report->status() . "\n";
        echo 'Audit Markdown report: ' . $base . ".md\n";
        echo 'Audit JSON report: ' . $base . ".json\n";
        echo 'Semantic L2 review prompt: ' . $base . ".prompt.md\n";
        echo 'Audit findings: ' . count($report->findings) . "\n";
        echo "Semantic review: NOT EXECUTED by this command; the emitted L2 prompt is the handoff for that review.\n";

        return $report->status() === 'fail' ? 1 : 0;
    }

    private function runCode(string $taskId, string $outputDir): int
    {
        $relativeDirectory = rtrim($outputDir, '/') . '/reviews';
        $directory = str_starts_with($relativeDirectory, '/') ? $relativeDirectory : rtrim($this->workspacePath, '/') . '/' . $relativeDirectory;
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create review directory: ' . $directory);
        }

        $prompt = (new CodeReviewPromptBuilder($this->workspacePath))->build($taskId, $outputDir);
        $path = $directory . '/' . $taskId . '.code.prompt.md';
        if (file_put_contents($path, $prompt) === false) {
            throw new RuntimeException('Unable to write code review prompt: ' . $path);
        }

        echo 'Review code prompt for ' . $taskId . ': ' . $relativeDirectory . '/' . $taskId . ".code.prompt.md\n";

        return 0;
    }

    private function unknownCommand(string $command): int
    {
        fwrite(\STDERR, "Unknown review command: {$command}\n\n");
        fwrite(\STDERR, $this->usage());
        return 1;
    }

    private function usage(): string
    {
        return <<<'TXT'
agent-recall-compiler review - deterministic evidence audits and review-prompt helpers.

Usage:
  agent-recall-compiler review help
  agent-recall-compiler review first-draft
  agent-recall-compiler review blindspots <task-id> [--output-dir PATH] [--contract-revision N --implementation-snapshot sha256:DIGEST]
  agent-recall-compiler review code <task-id> [--output-dir PATH]

Commands:
  help                  Show review help.
  first-draft           Print a compact context-light falsification lens for manual or automated review.
  blindspots <task-id>  Run a deterministic prerequisite/evidence audit and emit the semantic L2 blind-spot review prompt. The command does not execute that semantic review.
  code <task-id>        Generate an L2 code-review prompt from recall artifacts and task files.

TXT;
    }

    /**
     * @param list<string> $tokens
     * @return array{options: array<string, list<string>>, arguments: list<string>}
     */
    private function parseOptions(array $tokens): array
    {
        $options = [];
        $arguments = [];
        $i = 0;
        $count = count($tokens);
        while ($i < $count) {
            $token = $tokens[$i];
            if (str_starts_with($token, '--')) {
                $name = substr($token, 2);
                $value = '';
                if ($i + 1 < $count && !str_starts_with($tokens[$i + 1], '--')) {
                    $value = $tokens[$i + 1];
                    $i++;
                }
                $options[$name][] = $value;
            } else {
                $arguments[] = $token;
            }
            $i++;
        }

        return ['options' => $options, 'arguments' => $arguments];
    }

    /** @param array<string, list<string>> $options */
    private function contractRevision(array $options): ?int
    {
        $value = $this->stringOption($options, 'contract-revision');
        if ($value === null) {
            return null;
        }
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1) {
            throw new RuntimeException('--contract-revision requires a positive integer.');
        }

        return (int) $value;
    }

    /** @param array<string, list<string>> $options */
    private function implementationSnapshot(array $options): ?string
    {
        $value = $this->stringOption($options, 'implementation-snapshot');
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        if (preg_match('/^sha256:[a-f0-9]{64}$/', $value) !== 1) {
            throw new RuntimeException('--implementation-snapshot must be a sha256:<64 lowercase hex> digest.');
        }

        return $value;
    }

    /** @param array<string, list<string>> $options */
    private function stringOption(array $options, string $name): ?string
    {
        return $options[$name][0] ?? null;
    }
}
