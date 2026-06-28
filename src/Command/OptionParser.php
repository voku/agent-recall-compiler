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

        return new ParsedOptions($options, $arguments);
    }
}
