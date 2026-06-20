<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler;

/**
 * A single untrusted claim coming from another agent's review/feedback.
 *
 * It is deliberately not "guidance": it carries no scope, no approval, and no
 * authority. It only enters a briefing as something to verify, never as
 * something to apply.
 */
final readonly class FeedbackItem
{
    public function __construct(
        public string $source,
        public string $claim,
    ) {
    }
}
