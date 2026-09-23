<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler;

/**
 * Recall-side writer mirror of Learning's attribution source vocabulary: a
 * source other than the selected guidance that already prescribed the
 * decision credited to it.
 */
enum GuidanceOutcomeAttributionSource: string
{
    case TASK_PROMPT = 'task_prompt';
    case CONTRACT = 'contract';
    case SKILL = 'skill';
    case TEMPLATE = 'template';
    case CONSTRAINT = 'constraint';
    case REPOSITORY_DOCS = 'repository_docs';
}
