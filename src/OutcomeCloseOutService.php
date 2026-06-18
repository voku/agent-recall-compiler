<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler;

final readonly class OutcomeCloseOutService
{
    public function __construct(private OutcomeLogger $logger = new OutcomeLogger())
    {
    }

    public function close(OutcomeLoggingConfig $config): string
    {
        return $this->logger->log($config->rootConfig->root, $config->draftPath, $config->actor, $config->commit);
    }
}
