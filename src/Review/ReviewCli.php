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

        $parsed = $this->parseOptions($tokens);
        $taskId = $parsed['arguments'][0] ?? '';
        $outputDir = $this->stringOption($parsed['options'], 'output-dir') ?? '.agent-recall/current';

        if (!BlindSpotReviewer::isValidTaskId($taskId)) {
            fwrite(\STDERR, "[ERROR] Invalid or missing task id. Use an alphanumeric first character followed by letters, numbers, dots, underscores, or hyphens.\n");
            return 1;
        }

        try {
            return match ($command) {
                'blindspots' => $this->runBlindspots($taskId, $outputDir),
                'code' => $this->runCode($taskId, $outputDir),
                default => $this->unknownCommand($command),
            };
        } catch (RuntimeException $exception) {
            fwrite(\STDERR, '[ERROR] ' . $exception->getMessage() . "\n");
            return 1;
        }
    }

    private function runBlindspots(string $taskId, string $outputDir): int
    {
        $report = (new BlindSpotReviewer($this->workspacePath))->review($taskId, $outputDir);
        (new ReviewReportWriter($this->workspacePath))->write($report, $outputDir);

        $base = '.agent-recall/reviews/' . $taskId . '.blindspots';
        echo 'Review blindspots for ' . $taskId . ': ' . $report->status() . "\n";
        echo 'Markdown report: ' . $base . ".md\n";
        echo 'JSON report: ' . $base . ".json\n";
        echo 'L2 prompt: ' . $base . ".prompt.md\n";
        echo 'Findings: ' . count($report->findings) . "\n";

        return $report->status() === 'fail' ? 1 : 0;
    }

    private function runCode(string $taskId, string $outputDir): int
    {
        $directory = rtrim($this->workspacePath, '/') . '/.agent-recall/reviews';
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create review directory: ' . $directory);
        }

        $prompt = (new CodeReviewPromptBuilder($this->workspacePath))->build($taskId, $outputDir);
        $path = $directory . '/' . $taskId . '.code.prompt.md';
        if (file_put_contents($path, $prompt) === false) {
            throw new RuntimeException('Unable to write code review prompt: ' . $path);
        }

        echo 'Review code prompt for ' . $taskId . ': .agent-recall/reviews/' . $taskId . ".code.prompt.md\n";

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
agent-recall-compiler review - deterministic recall review helpers.

Usage:
  agent-recall-compiler review help
  agent-recall-compiler review blindspots <task-id> [--output-dir PATH]
  agent-recall-compiler review code <task-id> [--output-dir PATH]

Commands:
  help                  Show review help.
  blindspots <task-id>  Write deterministic blind-spot Markdown/JSON reports and an L2 prompt.
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
    private function stringOption(array $options, string $name): ?string
    {
        return $options[$name][0] ?? null;
    }
}
