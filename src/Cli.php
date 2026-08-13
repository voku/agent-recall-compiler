<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use voku\AgentRecallCompiler\Command\CompileCommand;
use voku\AgentRecallCompiler\Command\LogOutcomeCommand;
use voku\AgentRecallCompiler\Reflection\FutureWorkPromptBuilder;
use voku\AgentRecallCompiler\Reflection\FutureWorkScope;
use voku\AgentRecallCompiler\Review\ReviewCli;

final class Cli
{
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
                'compile' => (new CompileCommand())->run($this->compileTokensWithDefaultPaths($tokens)),
                'log-outcome' => (new LogOutcomeCommand())->run($tokens),
                'prompt' => $this->promptCommand($tokens),
                'review' => $this->reviewCommand($tokens),
                'help', '--help', '-h' => $this->helpCommand(),
                default => $this->unknownCommand($command),
            };
        } catch (RecallCompilationBlockedException $e) {
            fwrite(STDERR, "BLOCKED: " . $e->getMessage() . "\n");
            fwrite(STDERR, "Resolve the conflict in the approved guidance, then recompile.\n");
            return 1;
        } catch (Throwable $e) {
            fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
            return 1;
        }
    }

    private function helpCommand(): int
    {
        fwrite(STDOUT, "Usage: agent-recall-compiler <command> [options]\n\n");
        fwrite(STDOUT, "Commands:\n");
        fwrite(STDOUT, "  compile             Compile briefing prompts for a given task.\n");
        fwrite(STDOUT, "  log-outcome         Log a session's outcome feedback back into learning history.\n");
        fwrite(STDOUT, "  prompt              Render context-light prompt helpers such as future-work reflection.\n");
        fwrite(STDOUT, "  review              Generate deterministic blind-spot reports and L2 review prompts.\n\n");
        fwrite(STDOUT, "Prompt usage:\n");
        fwrite(STDOUT, "  prompt future-work [--scope project|task]\n\n");
        fwrite(STDOUT, "Options:\n");
        fwrite(STDOUT, "  --root PATH              Learning root (default: <cwd>/.agent-loop/learning).\n");
        fwrite(STDOUT, "  --task-brief PATH        Path to JSON task brief file.\n");
        fwrite(STDOUT, "  --output-dir PATH        Compile output (default: <cwd>/.agent-loop/recall/<task-id>).\n");
        fwrite(STDOUT, "  --task ID                Inline task ID selector.\n");
        fwrite(STDOUT, "  --description DESC       Inline task description text.\n");
        fwrite(STDOUT, "  --file PATH              Inline changed file path. Repeatable.\n");
        fwrite(STDOUT, "  --target CLASS::METHOD   Exact agent-map edit target. Requires --map-index. Repeatable.\n");
        fwrite(STDOUT, "  --tag LABEL              Inline relevance tag (domain/system/capability). Repeatable.\n");
        fwrite(STDOUT, "  --operating-prompt JSON  Task-selected operating prompt request. Repeatable.\n");
        fwrite(STDOUT, "  --operating-prompt-manifest PATH  Versioned operating prompt manifest. Repeatable.\n");
        fwrite(STDOUT, "  --feedback PATH          Untrusted peer-agent feedback file to assess (JSON or text).\n");
        fwrite(STDOUT, "  --map-index PATH         Agent-map JSON or TOON index. Required when --target is used.\n");
        fwrite(STDOUT, "  --map-root PATH          Project root used to verify map entries when the index came from another runtime.\n");
        fwrite(STDOUT, "  --map-search-index PATH  Derived agent-map search database (agent-map search-index build). Adds ranked candidates.\n");
        fwrite(STDOUT, "  --map-search-limit N     How many ranked candidates to include (default 8).\n");
        fwrite(STDOUT, "  --edit-focus TEXT        Narrow target source context around this literal. Repeatable.\n");
        fwrite(STDOUT, "  --kanban-context PATH    Optional stable JSON projection owned by the board integration.\n");
        fwrite(STDOUT, "  --document-manifest PATH Git-tracked scoped skill/ADR manifest. Repeatable.\n");
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

    /** @param list<string> $tokens */
    private function rootOption(array $tokens): ?string
    {
        return $this->optionValue($tokens, 'root');
    }

    /**
     * @param list<string> $tokens
     * @return list<string>
     */
    private function compileTokensWithDefaultPaths(array $tokens): array
    {
        $cwd = getcwd();
        if ($cwd === false) {
            throw new RuntimeException('Unable to determine current working directory.');
        }
        $cwd = rtrim(str_replace('\\', '/', $cwd), '/');

        if ($this->optionValue($tokens, 'root') === null) {
            $tokens[] = '--root';
            $tokens[] = $cwd . '/.agent-loop/learning';
        }

        if ($this->optionValue($tokens, 'output-dir') !== null) {
            return $tokens;
        }

        $taskBrief = $this->optionValue($tokens, 'task-brief');
        $taskId = $taskBrief !== null
            ? (new JsonTaskBriefResolver())->resolveFile($taskBrief)->id
            : $this->optionValue($tokens, 'task');

        if ($taskId === null || trim($taskId) === '') {
            return $tokens;
        }
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/', $taskId) !== 1 || str_contains($taskId, '..')) {
            throw new InvalidArgumentException('Task id cannot be used as the default recall output directory: ' . $taskId);
        }

        $tokens[] = '--output-dir';
        $tokens[] = $cwd . '/.agent-loop/recall/' . $taskId;

        return $tokens;
    }

    /** @param list<string> $tokens */
    private function optionValue(array $tokens, string $name): ?string
    {
        $prefix = '--' . $name . '=';
        $count = count($tokens);
        for ($i = 0; $i < $count; ++$i) {
            if (str_starts_with($tokens[$i], $prefix)) {
                $value = substr($tokens[$i], strlen($prefix));
                if ($value === '') {
                    throw new InvalidArgumentException('Option --' . $name . ' requires a value.');
                }

                return $value;
            }
            if ($tokens[$i] !== '--' . $name) {
                continue;
            }
            if ($i + 1 >= $count || str_starts_with($tokens[$i + 1], '--')) {
                throw new InvalidArgumentException('Option --' . $name . ' requires a value.');
            }

            return $tokens[$i + 1];
        }

        return null;
    }

    /** @param list<string> $tokens */
    private function promptCommand(array $tokens): int
    {
        $promptName = array_shift($tokens) ?? '';
        if ($promptName !== 'future-work') {
            throw new InvalidArgumentException('Unknown prompt: ' . ($promptName !== '' ? $promptName : '<missing>'));
        }

        $scope = $this->optionValue($tokens, 'scope') ?? FutureWorkScope::PROJECT->value;
        fwrite(STDOUT, (new FutureWorkPromptBuilder())->buildFromString($scope) . "\n");

        return 0;
    }

    /** @param list<string> $tokens */
    private function reviewCommand(array $tokens): int
    {
        $rootOption = $this->rootOption($tokens);
        $workspacePath = $rootOption !== null
            ? (new RecallRootResolver())->resolve($rootOption)->root
            : getcwd();
        if ($workspacePath === false) {
            throw new RuntimeException('Unable to determine current working directory.');
        }

        return (new ReviewCli($workspacePath))->run(array_merge(['agent-recall-compiler review'], $tokens));
    }
}
