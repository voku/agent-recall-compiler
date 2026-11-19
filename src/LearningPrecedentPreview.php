<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler;

use voku\AgentRecallCompiler\Output\RenderedLearningPrecedent;
use voku\AgentRecallCompiler\Provider\LearningNoteRecallProvider;
use voku\AgentRecallCompiler\Rendering\LearningPrecedentRanking;

/**
 * Previews which LearningNotes Recall would render for a candidate task scope,
 * without compiling or writing anything.
 *
 * A host calls this while a task's scope is still a candidate (for example
 * before a Contract is approved), so the precedent can still inform the design.
 * It uses the same selection, ranking and rendering budget as compilation.
 * Like compilation, it throws RecallCompilationBlockedException when a matching
 * note references missing repository evidence.
 *
 * The preview is a superset of what the compiled briefing renders: a full
 * compilation also drops a note whose pattern selected active guidance covers,
 * and guidance selection needs the whole compilation. Such a note's guidance
 * then reaches the briefing as active guidance instead, so showing the note
 * early loses nothing. Compiled output reports the final set through
 * CompiledRecallOutput::renderedLearningPrecedents().
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
        $payloads = [];
        foreach ($result->facts as $fact) {
            if ($fact->type === 'learning_precedent') {
                $payloads[] = $fact->payload;
            }
        }
        usort($payloads, LearningPrecedentRanking::compare(...));

        $rendered = [];
        foreach ($payloads as $payload) {
            $precedent = RenderedLearningPrecedent::fromFactPayload($payload);
            if ($precedent !== null) {
                $rendered[] = $precedent;
            }
        }

        return $rendered;
    }
}
