<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler;

final readonly class JsonTaskBriefResolver
{
    public function resolveFile(string $path): TaskBrief
    {
        return (new TaskBriefParser())->parseFile($path);
    }
}
