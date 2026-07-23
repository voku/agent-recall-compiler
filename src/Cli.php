<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler;

use Throwable;
use voku\AgentRecallCompiler\Command\CompileCommand;
use voku\AgentRecallCompiler\Command\LogOutcomeCommand;
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
                'compile' => (new CompileCommand())->run($tokens),
                'log-outcome' => (new LogOutcomeCommand())->run($tokens),
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
        fwrite(STDOUT, "  review              Generate deterministic blind-spot reports and L2 review prompts.\n\n");
        fwrite(STDOUT, "Options:\n");
        fwrite(STDOUT, "  --root PATH              Learning repository root directory.\n");
        fwrite(STDOUT, "  --task-brief PATH        Path to JSON task brief file.\n");
        fwrite(STDOUT, "  --output-dir PATH        Where to write output files (defaults to current directory).\n");
        fwrite(STDOUT, "  --task ID                Inline task ID selector.\n");
        fwrite(STDOUT, "  --description DESC       Inline task description text.\n");
        fwrite(STDOUT, "  --file PATH              Inline changed file path. Repeatable.\n");
        fwrite(STDOUT, "  --tag LABEL              Inline relevance tag (domain/system/capability). Repeatable.\n");
        fwrite(STDOUT, "  --feedback PATH          Untrusted peer-agent feedback file to assess (JSON or text).\n");
        fwrite(STDOUT, "  --map-index PATH         Optional agent-map JSON index for exact task-file navigation facts.\n");
        fwrite(STDOUT, "  --map-root PATH          Project root used to verify map entries when the index came from another runtime.\n");
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

    /**
     * @param list<string> $tokens
     */
    private function rootOption(array $tokens): ?string
    {
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i] !== '--root') {
                continue;
            }

            if ($i + 1 >= $count || str_starts_with($tokens[$i + 1], '--')) {
                throw new \InvalidArgumentException('Option --root requires a value.');
            }

            return $tokens[$i + 1];
        }

        return null;
    }

    /** @param list<string> $tokens */
    private function reviewCommand(array $tokens): int
    {
        $rootOption = $this->rootOption($tokens);
        $workspacePath = $rootOption !== null
            ? (new RecallRootResolver())->resolve($rootOption)->root
            : getcwd();
        if ($workspacePath === false) {
            throw new \RuntimeException('Unable to determine current working directory.');
        }

        return (new ReviewCli($workspacePath))->run(array_merge(['agent-recall-compiler review'], $tokens));
    }
}
