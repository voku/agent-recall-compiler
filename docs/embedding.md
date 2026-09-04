# Embedding Recall from PHP

Use the public PHP API when another package owns orchestration and needs Recall compilation as one deterministic step. The standalone CLI remains the human/script adapter; PHP consumers should not invoke `Cli` or `Command\CompileCommand` directly and should not parse human-oriented console output.

## Public contract

```php
<?php

declare(strict_types=1);

use voku\AgentRecallCompiler\CompileRequest;
use voku\AgentRecallCompiler\KanbanContextProjection;
use voku\AgentRecallCompiler\RecallCompiler;

$result = (new RecallCompiler())->compile(new CompileRequest(
    learningRoot: '/project/.agent-loop/learning',
    taskBrief: '/project/.agent-loop/runs/PROJECT-123/recall-input.json',
    outputDirectory: '/project/.agent-loop/recall/PROJECT-123',
    operatingPromptManifests: [
        '/project/vendor/voku/agent-recall-compiler/resources/skills/agent-recall-consumer/operating-prompts.json',
    ],
    documentManifests: [
        '/project/.agent-loop/recall-documents.json',
    ],
    kanbanContextProjection: new KanbanContextProjection(
        taskId: 'PROJECT-123',
        sourcePath: 'todo/cards/PROJECT-123.md',
        sourceRevision: 'sha256:card-revision',
        title: 'Keep the change bounded',
        lane: 'READY',
        status: 'Selected',
        priority: 1,
        nextAction: 'Implement the approved slice.',
    ),
    mapIndex: '/project/.agent-loop/map/php-symbols.json',
    mapRoot: '/project',
    mapSearchIndex: '/project/.agent-loop/map/search.sqlite',
));

$result->compilationId;
$result->bundleSha256;
$result->systemPath();
$result->validationPlanPath();
$result->factsPath();
$result->metaPath();
$result->bundlePath();
```

`CompileRequest` deliberately models only owner inputs needed by an embedding host. The task brief may be a normal task brief or the governed `governed_recall_input` envelope. Recall still verifies the governed Run/Contract binding through `TaskBriefParser`; the caller must not parse or recreate that rule.

Embedding hosts that already obtained a typed board card from its owner should use `KanbanContextProjection`. It carries only the bounded fields Recall consumes plus the semantic source path and exact card revision, and it does not require the host to persist a second context file. The legacy `kanbanContext` path remains available for standalone/file-oriented callers; a request may not provide both forms.

## Boundary

The embedded API owns:

- task-brief and governed-envelope parsing;
- Recall provider composition;
- operating-prompt, document, Kanban, and map inputs;
- compilation and conflict handling;
- artifact rendering and persistence;
- the typed successful compilation receipt.

The caller owns:

- selecting the approved task/Contract and paths it is authorized to use;
- deciding when compilation should occur;
- obtaining board state through the board owner's API before constructing a bounded `KanbanContextProjection`;
- deciding which optional owner inputs are available;
- consuming the returned artifacts in its own lifecycle.

The embedded API emits no CLI success report to `STDOUT`. This matters for hosts that expose a machine-readable JSON protocol around Recall compilation. Exceptions remain explicit failures; they are not converted into fake success results.

## Do not couple to internals

PHP hosts should not depend on:

- `Command\CompileCommand`;
- CLI option ordering or spelling;
- incidental success prose;
- the internal provider list;
- private artifact generation steps.

Use `CompileResult` for the stable compilation identity and common artifact paths. Read generated evidence files only when their content is actually needed; their existence is not proof that a coding agent consumed or satisfied them.
