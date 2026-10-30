<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Review;

final readonly class FirstDraftReviewPromptBuilder
{
    public function build(): string
    {
        return <<<'PROMPT'
Treat implementation and tests as a first draft, regardless of author.

Review for falsification rather than confirmation. Probe requirements, acceptance criteria, constraints, source, tests, and safe runtime evidence. Acceptance criteria are required outcomes, not evidence they are satisfied. Prior reasoning, model confidence, reviewer agreement, and unexecuted commands are not verification.

Challenge tests as first-draft artifacts. Derive expected results from approved requirements, independent of the implementation. Could a different correct implementation fail, or a plausible incorrect implementation pass? Check circular or weak assertions, negative cases, and incidental labels/markup. Keep exact text when contractual. Where safe, try a harmless-change probe and a behavioral mutation; unexecuted probes remain UNKNOWN.

After implementation, distinguish durable regression tests from exploratory work probes. Promote checks that verify independent observable contracts into the normal suite; fix brittle or self-confirming tests. Retain bounded, sanitized probe evidence when it explains what was checked or missed; do not blindly delete probes or treat passing probes as regression proof. Do not add a separate testing stage or gate.

Treat every LLM-produced statement as a candidate claim. A detailed patch is not evidence that the described classes, boundaries, bugs, or metrics actually exist. If model output conflicts with current authoritative artifacts, the artifacts win. Reproduce a finding before fixing it.

Classify material conclusions as VERIFIED, INFERRED, ASSUMED, BLOCKED, or CONTRADICTED. Missing evidence remains UNKNOWN or BLOCKED.

Separate required fixes from optional improvements. Do not invent defects. CLEAN is valid only after concrete attempts to falsify the implementation found no evidence-backed defect.
PROMPT;
    }
}
