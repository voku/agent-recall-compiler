# Operating prompt contracts

`agent-recall-compiler` can instantiate reusable, measurable execution contracts from versioned local manifests.

The compiler owns selection, validation, substitution, provenance, and rendering. The manifest owns the actual prompt semantics. This keeps repository guidance out of PHP source while still making the compiled `system.md` deterministic and replayable.

## Manifest schema

```json
{
  "schema_version": "1.0",
  "prompts": [
    {
      "id": "plan-horizon",
      "template": "Plan the next {{horizon}}, not merely the next step.\nCover the complete horizon with measurable milestones and explicit evidence."
    },
    {
      "id": "coverage-mutation",
      "template": "Increase coverage by at least {{minimum_percentage_points}} percentage points.\nRun {{mutation_command}} and use surviving meaningful mutants as evidence that the tests are still weak."
    }
  ]
}
```

Placeholders use the exact `{{name}}` form. Every placeholder must receive one scalar argument. Unknown arguments, missing arguments, duplicate prompt IDs, unknown selected prompts, and malformed placeholder syntax fail compilation.

## Task brief

```json
{
  "schema_version": "1.0",
  "task_id": "TEST-42",
  "goal": "Raise the verification bar for the parser.",
  "scope": ["src/Parser.php", "tests/ParserTest.php"],
  "operating_prompts": [
    {
      "id": "coverage-mutation",
      "arguments": {
        "minimum_percentage_points": 10,
        "mutation_command": "vendor/bin/infection --threads=max"
      }
    }
  ]
}
```

Compile it with:

```bash
agent-loop recall compile \
  --task-brief .agent-session/work-brief.json \
  --operating-prompt-manifest /path/to/operating-prompts.json
```

`agent-loop` forwards recall options to `agent-recall-compiler`, so no separate prompt runtime is required.

## Inline selection

For callers that already construct the task at the CLI boundary:

```bash
agent-loop recall compile \
  --task TEST-42 \
  --description "Raise the verification bar for the parser." \
  --operating-prompt-manifest /path/to/operating-prompts.json \
  --operating-prompt '{"id":"coverage-mutation","arguments":{"minimum_percentage_points":10,"mutation_command":"vendor/bin/infection --threads=max"}}'
```

The compiled prompt appears under `## Operating Contract` in `system.md`. The selected request, rendered content, source reference, and template digest are also included in recall facts and therefore in the canonical bundle digest.

## Design boundaries

- No prompt selection by LLM or keyword heuristic.
- No hidden threshold defaults. The caller must choose measurable values.
- No automatic execution. Recall compiles the contract; the host or workflow executes it.
- No duplicated first-party engineering guidance in this package. Keep reusable semantics in the repository that owns them, such as `voku/agent-skills`, and pass its manifest explicitly.
- Changing a selected template or its arguments changes the replayable compilation evidence.
