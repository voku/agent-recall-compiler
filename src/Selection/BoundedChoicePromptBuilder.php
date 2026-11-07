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
     * @param bool $enforceWorkflowApplicability Experimental opt-in; default preserves existing prompt and recorded benchmarks.
     *
     * @return string The formatted LLM prompt.
     */
    public function build(string $taskIntent, array $candidates, bool $enforceWorkflowApplicability = false): string
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

        if (!array_is_list($candidates)) {
            throw new InvalidArgumentException('candidates must be a zero-indexed list');
        }

        $seen = [];
        foreach ($candidates as $index => $candidate) {
            foreach (['id', 'title', 'description'] as $field) {
                if (!isset($candidate[$field]) || !is_string($candidate[$field]) || trim($candidate[$field]) === '') {
                    throw new InvalidArgumentException(sprintf('candidate %d requires a non-empty string %s', $index, $field));
                }
                if (preg_match('/[\\x00-\\x1F\\x7F]/', $candidate[$field]) === 1) {
                    throw new InvalidArgumentException(sprintf('candidate %d %s contains a control character', $index, $field));
                }
            }
            if (preg_match('/\\A[a-z][a-z0-9._-]*\\z/D', $candidate['id']) !== 1) {
                throw new InvalidArgumentException(sprintf('candidate %d has an invalid id', $index));
            }
            if (isset($seen[$candidate['id']])) {
                throw new InvalidArgumentException('duplicate candidate id: ' . $candidate['id']);
            }
            $seen[$candidate['id']] = true;
            if (isset($candidate['purpose']) && (
                !is_string($candidate['purpose'])
                || preg_match('/\\A[a-z][a-z0-9._-]*\\z/D', $candidate['purpose']) !== 1
            )) {
                throw new InvalidArgumentException(sprintf('candidate %d has an invalid purpose', $index));
            }

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
        if ($enforceWorkflowApplicability) {
            // Experimental hypothesis only: this is not validated model behavior.
            $lines[] = '';
            $lines[] = 'WORKFLOW_APPLICABILITY_EXPERIMENT';
            $lines[] = 'Only choose a recipe when its operational steps materially match the requested workflow or method, not merely because the task uses related technical vocabulary.';
            $lines[] = 'A generic process recipe may apply across languages and stacks when the user actually requests that process; an ordinary implementation task alone does not imply any generic recipe is needed.';
            $lines[] = 'If none of the offered recipes matches the requested workflow or method, select "none". This does not mean the underlying task is impossible.';
            $lines[] = 'Do not infer missing workflow instructions from superficial topic similarities, invent candidate IDs, or grant execution authority.';
        }

        $lines[] = '3. Respond ONLY with a single JSON object in this exact format:';
        $lines[] = '{"selected": "cand_<index>"} or {"selected": "none"}';
        $lines[] = 'Do not include any explanation or extra text.';

        return implode("\n", $lines);
    }
}
