<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler;

use voku\AgentRecallCompiler\Output\RenderedLearningPrecedent;
use voku\AgentRecallCompiler\Provider\LearningNoteRecallProvider;

/**
 * Previews which LearningNotes Recall would render for a candidate task scope,
 * without compiling or writing anything.
 *
 * A host calls this while a task's scope is still a candidate (for example
 * before a Contract is approved), so the precedent can still inform the design.
 * It uses the same selection, ranking and rendering budget as compilation.
 * Like compilation, it throws RecallCompilationBlockedException when a matching
 * note references missing repository evidence.
 */
final readonly class LearningPrecedentPreview
{
    public function __construct(
        private LearningNoteRecallProvider $provider = new LearningNoteRecallProvider(),
        private RecallRootResolver $rootResolver = new RecallRootResolver(),
    ) {
    }

    /**
     * @param list<string> $files candidate task files, relative to the project root
     * @param list<string> $tags
     * @return list<RenderedLearningPrecedent>
     */
    public function forCandidateScope(string $learningRoot, string $taskId, array $files, array $tags = []): array
    {
        $rootConfig = $this->rootResolver->resolve($learningRoot);
        if (!$this->provider->isAvailable($rootConfig)) {
            return [];
        }

        $result = $this->provider->collect(new TaskBrief($taskId, '', $files, tags: $tags), $rootConfig);
        $rendered = [];
        foreach ($result->facts as $fact) {
            if ($fact->type !== 'learning_precedent') {
                continue;
            }
            $precedent = RenderedLearningPrecedent::fromFactPayload($fact->payload);
            if ($precedent !== null) {
                $rendered[] = $precedent;
            }
        }

        return $rendered;
    }
}
