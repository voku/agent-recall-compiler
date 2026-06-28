<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Review;

enum ReviewSeverity: string
{
    case INFO = 'INFO';
    case WARN = 'WARN';
    case FAIL = 'FAIL';
}
