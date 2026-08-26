<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler;

final readonly class OperatingPromptPreview
{
    /** @param 1|2|null $level */
    public function __construct(
        public string $recipeId,
        public ?int $level,
        public ?string $content,
        public ?string $templateSha256,
        public OperatingPromptValidationResult $validation,
    ) {
    }
}
