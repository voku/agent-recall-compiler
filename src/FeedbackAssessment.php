<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler;

/**
 * A bundle of untrusted peer feedback to be assessed by the receiving agent.
 */
final readonly class FeedbackAssessment
{
    /**
     * @param list<FeedbackItem> $items
     */
    public function __construct(
        public array $items,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }
}
