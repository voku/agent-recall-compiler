<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Provider;

use voku\AgentRecallCompiler\RecallRootConfig;

interface ConditionalRecallProvider extends RecallProvider
{
    public function isAvailable(RecallRootConfig $rootConfig): bool;
}
