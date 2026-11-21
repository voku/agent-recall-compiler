<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Verification;

/**
 * One persisted `verification-plan.json`, bound to the exact bytes read.
 *
 * `sha256` is taken over the file bytes, matching `verification_plan_sha256`
 * in `meta.json` and `plan_sha256` in the verification key, so a consumer can
 * reject answers written against a plan that has since been recompiled.
 */
final readonly class VerificationPlanDocument
{
    /**
     * @param non-empty-string $path
     * @param non-empty-string $sha256
     * @param list<non-empty-string> $probeIds
     * @param list<non-empty-string> $checklistIds
     * @param array<string, mixed> $plan
     */
    public function __construct(
        public string $path,
        public string $sha256,
        public ?string $taskId,
        public ?string $target,
        public array $probeIds,
        public array $checklistIds,
        public array $plan,
    ) {
    }
}
