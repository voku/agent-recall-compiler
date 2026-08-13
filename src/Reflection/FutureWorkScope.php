<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Reflection;

enum FutureWorkScope: string
{
    case PROJECT = 'project';
    case TASK = 'task';
}
