<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Selection;

use InvalidArgumentException;

/**
 * Builds the bounded-choice prompt for optional LLM candidate reranking.
 * Restricts candidate choices strictly to verified, authoritative inputs plus "none".
 */
final readonly class BoundedChoicePromptBuilder
{
    /**
     * @param string $taskIntent The user query or task description.
     * @param list<array{id: string, title: string, description: string, purpose?: string}> $candidates
     *
     * @return string The formatted LLM prompt.
     */
    public function build(string $taskIntent, array $candidates): string
    {
        $task = trim($taskIntent);
        if ($task === '') {
            throw new InvalidArgumentException('task intent must not be empty');
        }

        $lines = [
            'You are an authoritative candidate relevance reviewer.',
            'A coding agent needs to select an applicable software engineering recipe for the given task intent.',
            '',
            '## Task Intent',
            $task,
            '',
            '## Candidate Recipes',
        ];

        foreach ($candidates as $index => $candidate) {
            $id = $candidate['id'];
            $title = $candidate['title'];
            $desc = $candidate['description'];
            $purpose = isset($candidate['purpose']) ? ' (purpose: ' . $candidate['purpose'] . ')' : '';
            $lines[] = sprintf('[cand_%d] id: "%s"%s', $index, $id, $purpose);
            $lines[] = '   title: ' . $title;
            $lines[] = '   description: ' . $desc;
        }

        $lines[] = '[none] None of the candidates above apply to this software engineering task.';
        $lines[] = '';
        $lines[] = '## Instructions';
        $lines[] = '1. Review the task intent against the candidate recipes.';
        $lines[] = '2. If the task is unrelated to software engineering (e.g. non-software requests, personal chores) or none of the candidates match, select "none".';
        $lines[] = '3. Respond ONLY with a single JSON object in this exact format:';
        $lines[] = '{"selected": "cand_<index>"} or {"selected": "none"}';
        $lines[] = 'Do not include any explanation or extra text.';

        return implode("\n", $lines);
    }
}
