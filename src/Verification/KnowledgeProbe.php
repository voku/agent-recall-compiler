<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Verification;

final readonly class KnowledgeProbe
{
    public function __construct(
        public string $id,
        public string $kind,
        public string $target,
        public string $question,
        public string $answerFormat,
        public bool $required = true,
    ) {
    }

    /** @return array{id: string, kind: string, target: string, question: string, answer_format: string, required: bool} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'target' => $this->target,
            'question' => $this->question,
            'answer_format' => $this->answerFormat,
            'required' => $this->required,
        ];
    }
}
