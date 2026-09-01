<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Provider;

interface ConditionalRecallProvider extends RecallProvider
{
    public function isAvailable(): bool;
}
