# Upgrading

## Default recall state moves below `.agent-loop/`

This release changes the standalone defaults used by `agent-recall-compiler`.

The canonical repository-local paths are now:

```text
.agent-loop/learning/
.agent-loop/recall/<task-id>/
```

The common historical layout was:

```text
infra/doc/agent-learning/
recall/
```

Migrate existing state explicitly:

```text
infra/doc/agent-learning/ -> .agent-loop/learning/
recall/                    -> .agent-loop/recall/
```

Learning-root discovery prefers `.agent-loop/learning/` when both the compact
and a historical root exist. `compile` writes to
`.agent-loop/recall/<task-id>/` when `--output-dir` is not supplied.

Explicit `--root` and `--output-dir` values remain authoritative. There is no
automatic copy, symlink, fallback merge, or dual-write between historical and
new roots.
