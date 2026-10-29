<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Review;

final readonly class FirstDraftReviewPromptBuilder
{
    public function build(): string
    {
        return <<<'PROMPT'
Treat the implementation and tests as a first draft, even if produced by you or paired with a confident rationale.

Review for falsification rather than confirmation. Try concrete failures against requirements, acceptance criteria, constraints, non-goals, source, tests, and safe runtime evidence. Acceptance criteria are required outcomes, not evidence they are satisfied. Prior reasoning, model confidence, reviewer consensus, prompts, and unexecuted commands are not verification.

Challenge tests as first-draft artifacts too. Trace expected outcomes to requirements independently of production logic. Ask whether a different correct implementation would fail or a plausible incorrect implementation would pass. Check circular or weak assertions, negative cases, and incidental labels/markup. Preserve exact strings where intentionally contractual. Where safe, execute a harmless-change probe and a behavioral mutation; unexecuted probes remain UNKNOWN.

Treat every LLM-produced statement as a candidate claim; re-ground against authoritative artifacts. Reproduce findings before fixing; hypotheses are not fixes.

Classify material conclusions as VERIFIED, INFERRED, ASSUMED, BLOCKED, or CONTRADICTED. If evidence is unavailable, keep it UNKNOWN or BLOCKED.

Separate required fixes from optional improvements. Do not manufacture findings. CLEAN is valid only after concrete attempts to falsify the implementation found no evidence-backed defect.
PROMPT;
    }
}
