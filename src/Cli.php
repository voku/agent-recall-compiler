<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler;

use Throwable;

final class Cli
{
    private readonly PathResolver $pathResolver;
    private readonly RecallRepository $repository;
    private readonly RecallDecisionEngine $decisionEngine;
    private readonly RecallPromptBuilder $promptBuilder;
    private readonly OutcomeLogger $outcomeLogger;

    public function __construct()
    {
        $this->pathResolver = new PathResolver();
        $this->repository = new RecallRepository();
        $this->decisionEngine = new RecallDecisionEngine();
        $this->promptBuilder = new RecallPromptBuilder();
        $this->outcomeLogger = new OutcomeLogger();
    }

    /**
     * @param list<string> $argv
     */
    public function run(array $argv): int
    {
        $tokens = $argv;
        array_shift($tokens);
        $command = array_shift($tokens) ?? 'help';

        try {
            return match ($command) {
                'compile' => $this->compileCommand($tokens),
                'log-outcome' => $this->logOutcomeCommand($tokens),
                'help', '--help', '-h' => $this->helpCommand(),
                default => $this->unknownCommand($command),
            };
        } catch (Throwable $e) {
            fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
            return 1;
        }
    }

    /**
     * @param list<string> $tokens
     */
    private function compileCommand(array $tokens): int
    {
        $parsed = $this->parseOptions($tokens);
        $rootOption = $this->stringOption($parsed['options'], 'root');
        $root = $this->pathResolver->resolve($rootOption);

        $briefPath = $this->stringOption($parsed['options'], 'task-brief');
        if ($briefPath !== null) {
            $task = (new TaskBriefParser())->parseFile($briefPath);
        } else {
            $taskId = $this->stringOption($parsed['options'], 'task');
            if ($taskId === null || trim($taskId) === '') {
                throw new \InvalidArgumentException('compile requires --task-brief or inline option --task');
            }
            $description = $this->stringOption($parsed['options'], 'description') ?? '';
            $files = $this->stringOptions($parsed['options'], 'file');
            $task = new TaskBrief($taskId, $description, $files);
        }

        $outputDir = $this->stringOption($parsed['options'], 'output-dir') ?? '.';
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0777, true);
        }
        $compilationId = $this->stringOption($parsed['options'], 'compilation-id') ?? $this->generateCompilationId($task->id);

        // Load guidance repo
        $memory = $this->repository->loadMemory($root);
        $activeGuidance = $this->repository->loadActiveGuidance($root);
        $rejectedGuidance = $this->repository->loadRejectedGuidance($root);
        $constraints = $this->repository->loadConstraintManifests($root);
        $outcomes = $this->repository->loadOutcomes($root);

        // Selection decision
        $result = $this->decisionEngine->decide($task, $activeGuidance, $rejectedGuidance, $outcomes, $constraints);

        // Build outputs
        $systemMd = $this->promptBuilder->buildSystemMd($task, $memory, $result);
        $validationPlan = $this->promptBuilder->buildValidationPlan($task, $result);
        $logDraft = $this->promptBuilder->buildRecallLogDraft($task, $result, $compilationId);
        $metaJson = $this->promptBuilder->buildMetaJson($task, $result, $compilationId, [
            'system.md' => hash('sha256', $systemMd),
            'validation-plan.md' => hash('sha256', $validationPlan),
            'recall-log.draft.json' => hash('sha256', $logDraft),
        ]);

        // Write outputs
        file_put_contents($outputDir . '/system.md', $systemMd);
        file_put_contents($outputDir . '/meta.json', $metaJson);
        file_put_contents($outputDir . '/validation-plan.md', $validationPlan);
        file_put_contents($outputDir . '/recall-log.draft.json', $logDraft);

        fwrite(STDOUT, sprintf("Briefing compiled successfully under: %s/\n", rtrim($outputDir, '/')));
        fwrite(STDOUT, sprintf("- compilation ID: %s\n", $compilationId));
        fwrite(STDOUT, sprintf("- system.md (selected guidance: %d, selected constraints: %d)\n", count($result->selectedGuidance), count($result->selectedConstraints)));
        fwrite(STDOUT, sprintf("- validation-plan.md\n"));
        fwrite(STDOUT, sprintf("- recall-log.draft.json\n"));

        return 0;
    }

    /**
     * @param list<string> $tokens
     */
    private function logOutcomeCommand(array $tokens): int
    {
        $parsed = $this->parseOptions($tokens);
        $rootOption = $this->stringOption($parsed['options'], 'root');
        $root = $this->pathResolver->resolve($rootOption);

        $draft = $this->stringOption($parsed['options'], 'draft') ?? $parsed['arguments'][0] ?? null;
        if ($draft === null || trim($draft) === '') {
            throw new \InvalidArgumentException('log-outcome requires --draft or draft path argument');
        }

        $actor = $this->stringOption($parsed['options'], 'by');
        if ($actor === null || trim($actor) === '') {
            throw new \InvalidArgumentException('log-outcome requires --by actor option');
        }

        $commit = $this->stringOption($parsed['options'], 'commit');
        if ($commit === null || trim($commit) === '') {
            throw new \InvalidArgumentException('log-outcome requires --commit option');
        }

        $outcomeId = $this->outcomeLogger->log($root, $draft, $actor, $commit);
        fwrite(STDOUT, sprintf("Logged outcome successfully: %s\n", $outcomeId));

        return 0;
    }

    private function helpCommand(): int
    {
        fwrite(STDOUT, "Usage: agent-recall-compiler <command> [options]\n\n");
        fwrite(STDOUT, "Commands:\n");
        fwrite(STDOUT, "  compile             Compile briefing prompts for a given task.\n");
        fwrite(STDOUT, "  log-outcome         Log a session's outcome feedback back into learning history.\n\n");
        fwrite(STDOUT, "Options:\n");
        fwrite(STDOUT, "  --root PATH              Learning repository root directory.\n");
        fwrite(STDOUT, "  --task-brief PATH        Path to JSON task brief file.\n");
        fwrite(STDOUT, "  --output-dir PATH        Where to write output files (defaults to current directory).\n");
        fwrite(STDOUT, "  --task ID                Inline task ID selector.\n");
        fwrite(STDOUT, "  --description DESC       Inline task description text.\n");
        fwrite(STDOUT, "  --file PATH              Inline changed file path. Repeatable.\n");
        fwrite(STDOUT, "  --compilation-id ID      Stable ID for this compile session.\n");
        fwrite(STDOUT, "  --draft PATH             Outcome draft file path for log-outcome.\n");
        fwrite(STDOUT, "  --by ACTOR               Actor name for log-outcome.\n");
        fwrite(STDOUT, "  --commit HASH            Commit hash or reference for log-outcome.\n\n");

        return 0;
    }

    private function unknownCommand(string $command): int
    {
        fwrite(STDERR, "Unknown command: " . $command . "\n");
        fwrite(STDERR, "Run 'agent-recall-compiler help' to view usage.\n");
        return 1;
    }

    private function generateCompilationId(string $taskId): string
    {
        $safeTaskId = preg_replace('/[^A-Za-z0-9_-]+/', '-', $taskId);
        if (!is_string($safeTaskId) || trim($safeTaskId) === '') {
            $safeTaskId = 'task';
        }

        return sprintf('compilation.%s.%s.%s', trim($safeTaskId, '-'), gmdate('Y-m-d-His'), bin2hex(random_bytes(4)));
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

    /**
     * @param array<string, list<string>> $options
     */
    private function stringOption(array $options, string $name): ?string
    {
        return isset($options[$name][0]) ? $options[$name][0] : null;
    }

    /**
     * @param array<string, list<string>> $options
     * @return list<string>
     */
    private function stringOptions(array $options, string $name): array
    {
        return isset($options[$name]) ? $options[$name] : [];
    }
}
