# Agent Recall Compiler (`voku/agent-recall-compiler`)

Deterministic L2 Meta-Prompt Compiler and Briefing Manager for Coding Agents.

[![Build Status](https://github.com/voku/agent-recall-compiler/actions/workflows/ci.yml/badge.svg)](https://github.com/voku/agent-recall-compiler/actions)
[![License](https://img.shields.io/github/license/voku/agent-recall-compiler.svg)](LICENSE)

This package forms the **recall layer** of the governed agent learning loop. It turns approved learnings (managed by `voku/agent-learning`) into precise, context-aware meta-prompts for subsequent coding sessions. 

Rather than overloading an LLM's system prompt with every rule ever created, the Recall Compiler selects only the rules relevant to the files the agent is about to modify. It also warns the agent of past rejections and failures to prevent repeating mistakes.

---

## Architecture & Workflow

```
                  ┌────────────────────────┐
                  │   Task File Scopes     │
                  └───────────┬────────────┘
                              │ (Matches scope prefixes)
                              ▼
┌──────────────┐    ┌──────────────────┐    ┌──────────────┐
│  MEMORY.md   │───►│  Recall Compiler │◄───│  History &   │
│ (Global mem) │    │  (Select Engine)  │    │   Outcomes   │
└──────────────┘    └────────┬─────────┘    └──────────────┘
                             │
            ┌────────────────┼────────────────┬──────────────┐
            ▼                ▼                ▼              ▼
     ┌───────────┐    ┌─────────────┐   ┌───────────┐  ┌───────────┐
     │ system.md │    │ validation- │   │ meta.json │  │ recall-   │
     │ (Briefing)│    │   plan.md   │   │  (Log)    │  │ log.draft │
     └───────────┘    └─────────────┘   └───────────┘  └───────────┘
```

---

## Key Features

- **Deterministic Scope Matching**: Evaluates the paths targeted by a task against the scopes of approved rules. Selects global rules (`MEMORY.md` and `/` or `*` scopes) along with sub-path specific active skills or constraints.
- **Conflict Detection**: Raises warnings if multiple active rules target the exact same codebase element or if duplicate directive wordings are detected.
- **Contradiction Guard**: Warns the agent if selected guidance matches the target patterns of previously rejected proposals.
- **Outcome-Driven Insights**: Inspects outcome logs to alert the agent if a selected rule was previously marked as `HARMFUL` or `IRRELEVANT` in past sessions, including developer comments.
- **Validation Briefing**: Dynamically compiles targeted validation and test verification instructions registered with the selected rules into a validation plan.
- **Loop Closure**: Prepares draft outcome feedback files so the agent can easily record what rules were helpful, irrelevant, or harmful at the end of the coding session.

---

## Installation

Install via Composer:

```bash
composer require --dev voku/agent-recall-compiler
```

---

## CLI Usage

The package exposes a binary at `vendor/bin/agent-recall-compiler` supporting two main operations:

### 1. Compile a Task Briefing

Prepares the system briefing, validation plan, metadata log, and draft outcome files for an active task.

```bash
vendor/bin/agent-recall-compiler compile \
  --root infra/doc/agent-learning \
  --task "ITPNG-367" \
  --description "Implement the new region-aware menu navigation" \
  --file "lib/application/navigation/entries/MenuEntryHead_M365_57.php" \
  --file "lib/application/navigation/MenuEntry_UnitCest.php" \
  --output-dir "."
```

#### Inline vs. File-based Briefing
Alternatively, you can pass a path to a pre-defined JSON file containing the task metadata:

```bash
vendor/bin/agent-recall-compiler compile \
  --root infra/doc/agent-learning \
  --task-brief "task-brief.json"
```

Where `task-brief.json` is:
```json
{
  "id": "ITPNG-367",
  "description": "Implement the new region-aware menu navigation",
  "files": [
    "lib/application/navigation/entries/MenuEntryHead_M365_57.php",
    "lib/application/navigation/MenuEntry_UnitCest.php"
  ]
}
```

#### Outputs Generated:
- **`system.md`**: Combined system prompt meta-prompt briefing containing selected active rules and warnings.
- **`validation-plan.md`**: Step-by-step test commands extracted from the selected rules to verify code compliance.
- **`meta.json`**: Technical metadata recording exactly which rules were loaded.
- **`recall-log.draft.json`**: A draft outcome log template populated with the selected rules to be completed at the end of the session.

---

### 2. Log Session Outcome

At the end of a coding session, once the validation commands pass and changes are committed, log the feedback to close the loop:

```bash
vendor/bin/agent-recall-compiler log-outcome \
  --root infra/doc/agent-learning \
  --draft "recall-log.draft.json" \
  --by "Lars Moelleken" \
  --commit "abc1234"
```

This appends a permanent, structured entry to `history/outcomes.jsonl`, which the compiler reads during future compilations to generate outcome-driven warnings.

---

## Integration Example

Integrating this into your project's `Makefile` automates the loop for any agent or human workflow:

```makefile
.PHONY: agent_recall_compile
agent_recall_compile:
	@if [ -z "$(TASK)" ]; then \
		echo "❌ Missing TASK parameter"; \
		echo "   Usage: make agent_recall_compile TASK=ITPNG-367 [FILE=path/to/file.php]"; \
		exit 1; \
	fi
	vendor/bin/agent-recall-compiler compile \
		--root infra/doc/agent-learning \
		--task "$(TASK)" \
		$(if $(DESC),--description "$(DESC)") \
		$(if $(FILE),--file "$(FILE)")

.PHONY: agent_recall_log_outcome
agent_recall_log_outcome:
	@if [ -z "$(DRAFT)" ] || [ -z "$(BY)" ] || [ -z "$(COMMIT)" ]; then \
		echo "❌ Missing DRAFT, BY, or COMMIT parameter"; \
		echo "   Usage: make agent_recall_log_outcome DRAFT=recall-log.draft.json BY=lars COMMIT=abc1234"; \
		exit 1; \
	fi
	vendor/bin/agent-recall-compiler log-outcome \
		--root infra/doc/agent-learning \
		--draft "$(DRAFT)" \
		--by "$(BY)" \
		--commit "$(COMMIT)"
```

---

## Development & Testing

Run the test suite using PHPUnit:

```bash
vendor/bin/phpunit
```

Run static analysis using PHPStan:

```bash
vendor/bin/phpstan analyse --configuration=phpstan.neon.dist
```

---

## License

This project is licensed under the MIT License. See [LICENSE](LICENSE) for details.
