<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Provider;

use voku\AgentRecallCompiler\RecallRootConfig;
use voku\AgentRecallCompiler\TaskBrief;

/**
 * Read-only source adapter for one recall knowledge source.
 *
 * Providers only describe source facts and legacy learning records. They must
 * not run task commands, mutate source data, or make model calls. The compiler
 * owns all cross-provider selection and rendering decisions.
 */
interface RecallProvider
{
    public function manifest(): RecallProviderManifest;

    public function collect(TaskBrief $task, RecallRootConfig $rootConfig): RecallProviderResult;
}
