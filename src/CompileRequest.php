<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler;

use InvalidArgumentException;

/**
 * Typed input for embedding Recall compilation from another PHP package.
 *
 * Paths remain caller-selected. Recall still owns parsing the governed task
 * brief, provider composition, compilation semantics, and generated artifacts.
 */
final readonly class CompileRequest
{
    /**
     * @param list<non-empty-string> $operatingPromptManifests
     * @param list<non-empty-string> $documentManifests
     * @param list<non-empty-string> $editFocus
     */
    public function __construct(
        public string $learningRoot,
        public string $taskBrief,
        public string $outputDirectory,
        public array $operatingPromptManifests = [],
        public array $documentManifests = [],
        public ?string $kanbanContext = null,
        public ?KanbanContextProjection $kanbanContextProjection = null,
        public ?string $mapIndex = null,
        public ?string $mapRoot = null,
        public ?string $mapSearchIndex = null,
        public int $mapSearchLimit = 8,
        public array $editFocus = [],
        public ?string $compilationId = null,
        public ?string $feedback = null,
    ) {
        $this->assertNonEmpty($this->learningRoot, 'learningRoot');
        $this->assertNonEmpty($this->taskBrief, 'taskBrief');
        $this->assertNonEmpty($this->outputDirectory, 'outputDirectory');
        $this->assertOptionalNonEmpty($this->kanbanContext, 'kanbanContext');
        $this->assertOptionalNonEmpty($this->mapIndex, 'mapIndex');
        $this->assertOptionalNonEmpty($this->mapRoot, 'mapRoot');
        $this->assertOptionalNonEmpty($this->mapSearchIndex, 'mapSearchIndex');
        $this->assertOptionalNonEmpty($this->compilationId, 'compilationId');
        $this->assertOptionalNonEmpty($this->feedback, 'feedback');
        $this->assertStringList($this->operatingPromptManifests, 'operatingPromptManifests');
        $this->assertStringList($this->documentManifests, 'documentManifests');
        $this->assertStringList($this->editFocus, 'editFocus');

        if ($this->kanbanContext !== null && $this->kanbanContextProjection !== null) {
            throw new InvalidArgumentException('kanbanContext and kanbanContextProjection are mutually exclusive.');
        }
        if ($this->mapSearchLimit < 1) {
            throw new InvalidArgumentException('mapSearchLimit must be a positive integer.');
        }
        if ($this->mapSearchIndex !== null && $this->mapIndex === null) {
            throw new InvalidArgumentException('mapSearchIndex requires mapIndex.');
        }
    }

    private function assertNonEmpty(string $value, string $name): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException($name . ' must be a non-empty string.');
        }
    }

    private function assertOptionalNonEmpty(?string $value, string $name): void
    {
        if ($value !== null) {
            $this->assertNonEmpty($value, $name);
        }
    }

    /** @param list<mixed> $values */
    private function assertStringList(array $values, string $name): void
    {
        foreach ($values as $value) {
            if (!is_string($value) || trim($value) === '') {
                throw new InvalidArgumentException($name . ' must contain only non-empty strings.');
            }
        }
    }
}
