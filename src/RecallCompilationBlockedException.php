<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler;

use RuntimeException;

/**
 * Thrown when a briefing cannot be compiled because the approved guidance
 * is in an unresolved state (conflicting directives, contradicted rejections,
 * unapproved or superseded items, or missing required validation).
 *
 * The compiler fails closed: it does not emit a partial, silently-degraded
 * prompt. The maintainer must resolve the conflict before a briefing is built.
 */
final class RecallCompilationBlockedException extends RuntimeException
{
}
