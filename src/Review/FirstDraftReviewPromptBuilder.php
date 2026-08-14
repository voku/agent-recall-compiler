<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Review;

final readonly class FirstDraftReviewPromptBuilder
{
    public function build(): string
    {
        return <<<'PROMPT'
Treat the current implementation as a first draft, even if it was produced by you or arrives with a confident rationale.

Review for falsification rather than confirmation. Assume there may be mistakes and try to prove concrete failures against the stated requirements, acceptance criteria, constraints, non-goals, current source, tests, static analysis, and safe runtime evidence.

Acceptance criteria are required outcomes, not evidence that they are satisfied. Prior reasoning, model confidence, reviewer consensus, prompt construction, and unexecuted commands are not verification.

Classify material conclusions honestly as VERIFIED, INFERRED, ASSUMED, BLOCKED, or CONTRADICTED. If evidence required to decide a material concern is unavailable, keep it UNKNOWN or BLOCKED rather than calling the implementation clean.

Distinguish required correctness or acceptance fixes from optional improvements. Do not manufacture findings merely to be adversarial. CLEAN is valid only after concrete attempts to falsify the implementation found no evidence-backed defect.
PROMPT;
    }
}
