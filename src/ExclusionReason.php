<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler;

enum ExclusionReason: string
{
    case NO_SCOPE_OVERLAP = 'no_scope_overlap';
    case INACTIVE = 'inactive';
    case STALE = 'stale';
    case SUPERSEDED = 'superseded';
    case CONFLICTING = 'conflicting';
    case REJECTED = 'rejected';
    case INVALID_SCHEMA = 'invalid_schema';
}
