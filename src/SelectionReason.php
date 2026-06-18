<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler;

enum SelectionReason: string
{
    case GLOBAL = 'global';
    case EXPLICIT = 'explicit';
    case SCOPE_OVERLAP = 'scope_overlap';
    case CONSTRAINT_SCOPE = 'constraint_scope';
    case REQUIRED_VALIDATION = 'required_validation';
    case REJECTED_GUIDANCE_WARNING = 'rejected_guidance_warning';
    case OUTCOME_WARNING = 'outcome_warning';
}
