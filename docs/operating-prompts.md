# Operating prompt recipes

`agent-recall-compiler` can compile reusable prompt recipes together with task-specific recall context.

The important distinction is prompt level:

- **L2 recipe**: tells an agent how to build a project-specific L1 operational prompt from the current recall bundle.
- **L1 contract**: an already executable instruction that can be applied directly to the task.

Most reusable engineering advice belongs at L2. The reusable part is the method and quality bar; the concrete files, symbols, commands, architecture, risks, and invariants belong to the project context. Context is therefore resolved at compile time instead of hard-coded into a generic prompt library.

The caller selects prompt requests. The compiler validates and resolves the selected recipe, substitutes explicit parameters, records provenance, and renders the result beside the task-specific context in `system.md`. The manifest owns the reusable prompt semantics.

## The target shape

A project-specific L1 operational prompt should have exactly five parts:

```text
Goal         = measurable outcome / minimum floor
Context      = exact repository search anchors and known facts
Constraints  = invariants and scope boundaries
Verification = exact repository-supported measurement procedure
Done When    = observable result and stopping condition
```

`Verification` and `Done When` are deliberately separate. Verification answers **how reality is measured**. Done When answers **which observed result is sufficient to stop**.

An L2 recipe therefore does **not** say only "increase coverage" or "plan further ahead". It instructs the next agent to use the current recall context to construct something like:

```text
Goal:
Increase coverage for src/Parser.php by at least 10 percentage points.

Context:
Use src/Parser.php, tests/ParserTest.php, the existing parser fixtures, and the repository's Infection configuration.
The exact coverage command is UNKNOWN; resolve it from repository scripts or CI before implementation.

Constraints:
Keep the public API unchanged. Do not weaken existing assertions. Do not add PHPStan ignores.

Verification:
Run `vendor/bin/phpunit tests/ParserTest.php`.
Run `vendor/bin/phpstan analyse -c phpstan.neon.dist`.
Run the repository-supported coverage command discovered from Context; do not invent one.
Run `vendor/bin/infection --threads=max` because that exact command was supplied by the selected recipe argument.

Done When:
The focused tests and PHPStan pass, measured coverage is at least 10 percentage points higher, and meaningful mutants are killed or explicitly reported as remaining risk.
```

That L1 prompt is intentionally project-specific. The reusable L2 recipe only defines how to derive it. A missing command remains `UNKNOWN`; descriptive phrases such as "run the project's tests" are not substitutes for executable Verification.

## Deterministic project capability evidence

When the configured/inferred project root contains supported repository evidence, recall adds a bounded `project.capabilities` fact. The provider does not crawl arbitrary source code or ask an LLM to guess the toolchain. It reads only supported project metadata such as:

- `composer.json`, including the PHP runtime constraint and exact Composer scripts;
- PHPUnit, Codeception, PHPStan, Infection, php-cs-fixer, and Rector configuration files when present;
- known development-tool package constraints from Composer;
- `.github/workflows/*.yml` and `*.yaml` file names as CI anchors.

Package presence proves that a tool exists; it does **not** prove an invocation command. Exact commands come from repository scripts, configured task validation, constraints, or other explicit project evidence. If the command cannot be resolved, the L2 prompt must keep it `UNKNOWN` and make discovery part of Context.

## Manifest schema

Every recipe explicitly declares whether it is L1 or L2:

```json
{
  "schema_version": "1.0",
  "prompts": [
    {
      "id": "plan-horizon",
      "level": 2,
      "template": "Create a project-specific planning prompt for the next {{horizon}}. Use the current repository architecture, task state, dependencies, validation capabilities, and unresolved unknowns from recall context."
    },
    {
      "id": "evidence-report",
      "level": 1,
      "template": "Do not claim success beyond observable evidence. Report exact verification commands, relevant results, skipped verification, and remaining risks."
    }
  ]
}
```

`level` is required and must be `1` or `2`. Placeholders use the exact `{{name}}` form. Every placeholder must receive one boolean, integer, or string argument. Unknown arguments, missing arguments, duplicate prompt IDs, unknown selected prompts, and malformed placeholder syntax fail compilation.

## L2 construction contract

When a selected recipe has `level: 2`, `system.md` receives an `L2 Operational Prompt Construction` section. It tells the consuming agent to synthesize a concrete L1 prompt with `Goal`, `Context`, `Constraints`, `Verification`, and `Done When`.

The L2 pass must:

- preserve numeric floors and explicit stopping conditions from the recipe;
- prefer exact repository facts over generic advice;
- use known files, symbols, callers, tests, project documents, task state, and constraints as context anchors;
- put exact repository-supported commands and probes in Verification;
- keep Verification separate from the acceptance result in Done When;
- avoid generic placeholders when recall already contains a concrete value;
- never invent repository commands, tools, APIs, or architectural rules;
- mark missing evidence as `UNKNOWN` or make evidence discovery part of the generated Context section;
- remove hedge language such as "maybe", "try to", "consider", and "if possible";
- stop after constructing the L1 prompt rather than implementing the task during the L2 pass.

This is the point of putting the mechanism in recall rather than storing a pile of polished generic prompts: the reusable recipe stays small, while the generated L1 prompt gets shaped by the actual project.

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

The task selects a recipe and supplies hard task policy such as the minimum percentage-point increase. Repository context still comes from recall providers rather than being repeated in the request.

## Compile directly

```bash
agent-recall-compiler compile \
  --task-brief .agent-session/work-brief.json \
  --operating-prompt-manifest /path/to/operating-prompts.json
```

## Through agent-loop

`voku/agent-loop` owns the governed orchestration around this compiler. Its current L2 flow is deliberately two-pass:

```text
PLAN -> APPROVE -> CONTEXT -> CONTRACT -> IMPLEMENT
```

`workflow plan` records the manifest source and selected recipe/arguments in the revisioned WorkBrief. `workflow approve` compiles recall from that approved policy. The agent then uses the L2 section plus current recall evidence to construct exactly one project-specific L1 document and persists it through the workflow contract gate:

```bash
agent-loop workflow contract TEST-42 \
  --status ready \
  --from .agent-loop/tmp/TEST-42-l1.md \
  --by agent
```

For L2-selected tasks, `agent-loop` binds the persisted execution contract to the current WorkBrief revision, recall bundle, prompt semantics, and content digest. Mutating edit runners remain blocked while that contract is `missing`, `stale`, `invalid`, `blocked`, or `rejected`. A re-plan or recall change therefore cannot silently reuse an older L1.

The recall compiler itself still does not execute the generated prompt and does not own workflow state. Its job ends at deterministic context, recipe resolution, rendering, provenance, and outcome evidence.

## Inline selection

```bash
agent-recall-compiler compile \
  --task TEST-42 \
  --description "Raise the verification bar for the parser." \
  --file src/Parser.php \
  --file tests/ParserTest.php \
  --operating-prompt-manifest /path/to/operating-prompts.json \
  --operating-prompt '{"id":"coverage-mutation","arguments":{"minimum_percentage_points":10,"mutation_command":"vendor/bin/infection --threads=max"}}'
```

The selected request, recipe level, rendered content, source reference, template digest, and prior recipe outcome counts are included in recall facts and therefore in the canonical bundle digest.

## Recipe outcome evidence

Selection is not proof that a recipe helped. The recall outcome draft contains one `operating_prompt_outcomes` entry per selected recipe with the selected argument digest, `applied`, an outcome, evidence, and an optional comment.

Final `helpful`, `irrelevant`, or `harmful` classifications require concrete evidence. `helpful` and `harmful` additionally require `applied=true`. Finalized events are stored separately from normal guidance outcomes in:

```text
history/operating-prompt-outcomes.jsonl
```

Future compilations expose aggregate counts for the selected recipe. Those counts are evidence for later human review of the recipe catalog; they do not automatically rewrite, promote, weaken, or retire a recipe.

## Design boundaries

- No prompt selection by LLM or keyword heuristic.
- No hidden threshold defaults. The caller chooses measurable task policy.
- No project-specific paths or commands baked into reusable first-party recipes unless they are explicit parameters.
- No automatic assumption that an L2 recipe is executable task work. L2 constructs L1; L1 executes.
- No universal repository crawler; capability discovery is bounded and evidence-backed.
- No automatic recipe self-modification from outcome statistics.
- No duplicated first-party engineering guidance in this package. Keep reusable semantics in the repository that owns them, such as `voku/agent-skills`, and pass its manifest explicitly.
- Changing a selected recipe, level, template, argument, relevant project capability evidence, or prior recipe outcome history changes replayable compilation evidence.
