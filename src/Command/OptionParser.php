<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Command;

final class OptionParser
{
    /**
     * @param list<string> $tokens
     */
    public function parse(array $tokens): ParsedOptions
    {
        $options = [];
        $arguments = [];
        $i = 0;
        $count = count($tokens);
        while ($i < $count) {
            $token = $tokens[$i];
            if (str_starts_with($token, '--')) {
                $name = substr($token, 2);
                if ($i + 1 >= $count || str_starts_with($tokens[$i + 1], '--')) {
                    throw new \InvalidArgumentException(sprintf('Option --%s requires a value.', $name));
                }
                $options[$name][] = $tokens[$i + 1];
                $i++;
            } else {
                $arguments[] = $token;
            }
            $i++;
        }

        return new ParsedOptions($options, $arguments);
    }
}
