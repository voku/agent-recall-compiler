# Contributing

Thanks for considering a contribution to `voku/agent-recall-compiler`.

## Scope

`agent-recall-compiler` is the recall layer of a governed coding-agent workflow.
It turns task intent plus bounded repository evidence into a replayable briefing,
project-specific prompt inputs, validation obligations, and review artifacts.

## Development setup

```bash
git clone https://github.com/voku/agent-recall-compiler.git
cd agent-recall-compiler
composer install
```

## Before opening a PR

```bash
composer test      # PHPUnit
composer phpstan   # PHPStan at max level
composer ci        # Runs composer validate --strict, test, and phpstan
```

All checks must pass cleanly.

## Code style

- `declare(strict_types=1)` in every PHP file.
- `final` classes and `readonly` value objects wherever applicable.
- Strict typing with zero PHPStan errors.
- Tests located under `tests/` mirroring the `src/` directory structure.
- Clear commit messages and focused pull requests.
