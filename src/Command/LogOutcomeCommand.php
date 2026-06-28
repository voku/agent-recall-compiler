<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Command;

use voku\AgentRecallCompiler\OutcomeLogger;
use voku\AgentRecallCompiler\RecallRootResolver;

final class LogOutcomeCommand
{
    public function __construct(
        private readonly RecallRootResolver $rootResolver = new RecallRootResolver(),
        private readonly OutcomeLogger $outcomeLogger = new OutcomeLogger(),
        private readonly OptionParser $optionParser = new OptionParser(),
    ) {}

    /** @param list<string> $tokens */
    public function run(array $tokens): int
    {
        $parsed = $this->optionParser->parse($tokens);
        $root = $this->rootResolver->resolve($parsed->stringOption('root'))->root;

        $draft = $parsed->stringOption('draft') ?? $parsed->arguments[0] ?? null;
        if ($draft === null || trim($draft) === '') {
            throw new \InvalidArgumentException('log-outcome requires --draft or draft path argument');
        }

        $actor = $parsed->stringOption('by');
        if ($actor === null || trim($actor) === '') {
            throw new \InvalidArgumentException('log-outcome requires --by actor option');
        }

        $commit = $parsed->stringOption('commit');
        if ($commit === null || trim($commit) === '') {
            throw new \InvalidArgumentException('log-outcome requires --commit option');
        }

        $outcomeId = $this->outcomeLogger->log($root, $draft, $actor, $commit);
        fwrite(\STDOUT, sprintf("Logged outcome successfully: %s\n", $outcomeId));

        return 0;
    }
}
