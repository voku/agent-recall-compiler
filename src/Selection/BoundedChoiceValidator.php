<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Selection;

use JsonException;

/**
 * Validates the raw model output from a bounded-choice reranker.
 * Fails closed on any unexpected, hallucinated, or unparseable output.
 */
final readonly class BoundedChoiceValidator
{
    /**
     * @param string $rawResponse The raw text returned by the model.
     * @param list<string> $validCandidateIds The ordered list of authorized candidate IDs.
     *
     * @return string|null The selected candidate ID, or null if "none" or invalid (fail closed).
     */
    public function validate(string $rawResponse, array $validCandidateIds): ?string
    {
        $text = trim($rawResponse);
        if ($text === '') {
            return null;
        }

        $selected = null;

        // Try parsing JSON first
        try {
            $data = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($data) && isset($data['selected']) && is_string($data['selected'])) {
                $selected = trim($data['selected']);
            }
        } catch (JsonException) {
            // Fall back to extracting regex from non-strict responses
            if (preg_match('/"selected"\s*:\s*"([^"]+)"/', $text, $matches) === 1) {
                $selected = trim($matches[1]);
            } elseif (preg_match('/\b(cand_\d+|none)\b/i', $text, $matches) === 1) {
                $selected = strtolower(trim($matches[1]));
            }
        }

        if ($selected === null || strtolower($selected) === 'none') {
            return null;
        }

        // Check if selected is an index format: cand_<N>
        if (preg_match('/\Acand_(\d+)\z/i', $selected, $matches) === 1) {
            $index = (int) $matches[1];
            if (isset($validCandidateIds[$index])) {
                return $validCandidateIds[$index];
            }

            // Out-of-bounds candidate index -> fail closed
            return null;
        }

        // Check if selected is directly one of the authorized candidate IDs
        if (in_array($selected, $validCandidateIds, true)) {
            return $selected;
        }

        // Any other unrecognized text or hallucinated ID -> fail closed
        return null;
    }
}
