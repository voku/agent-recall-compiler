<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Verification;

final readonly class CompiledVerificationPlan
{
    public function __construct(
        public VerificationPlan $plan,
        public VerificationKey $key,
    ) {
    }
}
