<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Review;

final readonly class FirstDraftReviewPromptBuilder
{
    public function build(): string
    {
        return <<<'PROMPT'
Treat the current implementation as a first draft, even if produced by you or paired with a confident rationale.

Review for falsification rather than confirmation. Try to prove concrete failures against requirements, acceptance criteria, constraints, non-goals, current source, tests, static analysis, and safe runtime evidence.

Acceptance criteria are required outcomes, not evidence they are satisfied. Prior reasoning, model confidence, reviewer consensus, prompt construction, and unexecuted commands are not verification.

Treat every LLM-produced statement as a candidate claim, not repository truth. Re-ground material claims against current authoritative artifacts or deterministic evidence before acting. A detailed patch is not evidence that the described classes, boundaries, bugs, or metrics actually exist. If model output conflicts with current authoritative artifacts, the artifacts win. Reproduce a finding before fixing it. Review findings are investigation candidates, not instructions to modify code.

Classify material conclusions as VERIFIED, INFERRED, ASSUMED, BLOCKED, or CONTRADICTED. If needed evidence is unavailable, keep it UNKNOWN or BLOCKED.

Separate required fixes from optional improvements. Do not manufacture findings. CLEAN is valid only after concrete attempts to falsify the implementation found no evidence-backed defect.
PROMPT;
    }
}
