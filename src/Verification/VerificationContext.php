<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Verification;

use voku\AgentMap\Context\EditContextPlan;
use voku\AgentMap\Index\AgentMapIndex;

final readonly class VerificationContext
{
    public function __construct(
        public AgentMapIndex $map,
        public EditContextPlan $editContext,
    ) {
    }
}
