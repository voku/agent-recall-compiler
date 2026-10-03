<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Output;

/**
 * One entry of the persisted "what shaped this context" explanation.
 *
 * `$subjectId` is the id of the owner record the entry is about - the LearningNote id for a
 * `learning_precedent` - carried as a field so a consumer relating the entry to that record
 * does not have to take apart `$id`, which is Recall's own format. `null` means "not
 * recorded": the entry is not about an owner record, or the report was persisted before the
 * field existed. It never means "there is no such record".
 */
final readonly class CompiledContextExplainItem
{
    /** @param list<string> $evidenceIds */
    public function __construct(
        public string $id,
        public string $kind,
        public string $what,
        public string $why,
        public string $how,
        public string $authority,
        public string $use,
        public ContextExplainState $state,
        public bool $selected,
        public ?string $sourceRef,
        public array $evidenceIds,
        public ?string $whyNot,
        public ?string $subjectId = null,
    ) {
    }
}
