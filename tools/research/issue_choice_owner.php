<?php

declare(strict_types=1);

use voku\AgentRecallCompiler\Selection\BoundedChoicePromptBuilder;
use voku\AgentRecallCompiler\Selection\BoundedChoiceValidator;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

/**
 * Research-only adapter to the shipped PHP owner contracts.
 * Model output has no workflow authority.
 *
 * @phpstan-type PromptCandidate array{id: string, title: string, description: string, purpose?: string}
 */
try {
    $mode = $argv[1] ?? '';
    if (!in_array($mode, ['prompt', 'validate'], true)) {
        throw new InvalidArgumentException('Expected prompt or validate');
    }

    $wire = stream_get_contents(STDIN);
    if (!is_string($wire) || strlen($wire) > 32768 || $wire === '') {
        throw new RuntimeException('Input JSON must be non-empty and at most 32768 bytes');
    }

    /** @var mixed $input */
    $input = json_decode($wire, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($input)) {
        throw new InvalidArgumentException('Input must be a JSON object');
    }

    if ($mode === 'prompt') {
        $intent = $input['intent'] ?? null;
        $candidates = $input['candidates'] ?? null;
        if (!is_string($intent) || trim($intent) === '' || !is_array($candidates)) {
            throw new InvalidArgumentException('Prompt requires task intent and candidate list');
        }

        $applicability = $input['enforce_workflow_applicability'] ?? false;
        if (!is_bool($applicability)) {
            throw new InvalidArgumentException('Applicability flag must be boolean');
        }

        /** @var list<PromptCandidate> $candidates */
        $prompt = (new BoundedChoicePromptBuilder())->build($intent, $candidates, $applicability);
        $output = ['prompt' => $prompt];
    } else {
        $rawResponse = $input['raw_response'] ?? null;
        $candidateIds = $input['candidate_ids'] ?? null;
        if (!is_string($rawResponse) || !is_array($candidateIds) || !array_is_list($candidateIds)) {
            throw new InvalidArgumentException('Validation requires raw response and ordered IDs');
        }
        foreach ($candidateIds as $candidateId) {
            if (!is_string($candidateId) || $candidateId === '') {
                throw new InvalidArgumentException('Candidate ID must be a nonempty string');
            }
        }

        /** @var list<string> $candidateIds */
        $selected = (new BoundedChoiceValidator())->validate($rawResponse, $candidateIds);
        $explicitNone = preg_match(
            '/\A[ \t\r\n]*\{[ \t\r\n]*"selected"[ \t\r\n]*:[ \t\r\n]*"none"[ \t\r\n]*\}[ \t\r\n]*\z/D',
            $rawResponse,
        ) === 1;

        $output = [
            'selected' => $selected,
            'status' => $selected !== null ? 'selected' : ($explicitNone ? 'explicit_none' : 'invalid_output'),
        ];
    }

    echo json_encode($output, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Research owner bridge failed: ' . $error::class . ': ' . $error->getMessage() . "\n");
    exit(1);
}
