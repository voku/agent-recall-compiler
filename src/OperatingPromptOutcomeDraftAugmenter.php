<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler;

use JsonException;
use RuntimeException;

final readonly class OperatingPromptOutcomeDraftAugmenter
{
    public function augment(string $draftJson, TaskBrief $task): string
    {
        try {
            $data = json_decode($draftJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Cannot augment malformed recall outcome draft: ' . $exception->getMessage(), 0, $exception);
        }
        if (!is_array($data)) {
            throw new RuntimeException('Recall outcome draft must decode to an object.');
        }

        $data['operating_prompt_outcomes'] = array_map(
            static fn (OperatingPromptRequest $request): array => [
                'prompt_id' => $request->id,
                'arguments_sha256' => CanonicalJson::digest($request->arguments),
                'selected' => true,
                'applied' => false,
                'outcome' => OutcomeValue::UNKNOWN->value,
                'evidence' => [],
                'comment' => null,
            ],
            $task->operatingPrompts,
        );

        return CanonicalJson::pretty($data);
    }
}
