<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Selection;

use JsonException;
use stdClass;

/**
 * Validates a single closed-format model choice without trusting model prose.
 *
 * A failed or unavailable model response is never workflow authority.
 */
final readonly class BoundedChoiceValidator
{
    /**
     * @param string $rawResponse Raw, untrusted model output.
     * @param list<string> $validCandidateIds Current ordered, verified candidate IDs.
     *
     * @return string|null The selected verified candidate ID or null on 'none'/invalid.
     */
    public function validate(string $rawResponse, array $validCandidateIds): ?string
    {
        if (trim($rawResponse) === '' || strlen($rawResponse) > 1024) {
            return null;
        }

        try {
            $result = json_decode($rawResponse, false, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!$result instanceof stdClass) {
            return null;
        }

        $properties = get_object_vars($result);
        if (count($properties) !== 1 || !array_key_exists('selected', $properties)) {
            return null;
        }

        $selected = $properties['selected'];
        if (!is_string($selected) || $selected === 'none') {
            return null;
        }

        // Only canonical cand_N indexes from this single prompt are permitted.
        // Direct IDs, prose, case variations, leading zeros and extra JSON
        // properties must never be silently promoted to a decision.
        if (preg_match('/\Acand_(0|[1-9][0-9]*)\z/D', $selected, $matches) !== 1) {
            return null;
        }

        // Avoid conversion of arbitrarily large model-supplied integers.
        $indexText = $matches[1];
        if (strlen($indexText) > 9) {
            return null;
        }

        $index = (int) $indexText;

        return $validCandidateIds[$index] ?? null;
    }
}
