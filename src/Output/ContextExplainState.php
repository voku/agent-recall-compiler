<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Output;

enum ContextExplainState: string
{
    case VERIFIED = 'verified';
    case INFERRED = 'inferred';
    case UNKNOWN = 'unknown';
    case BLOCKED = 'blocked';
}
