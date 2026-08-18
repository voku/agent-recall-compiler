# Dependency readiness for lifecycle hosts

`agent-recall-compiler` is a domain owner, not a second workflow orchestrator.

For PHP hosts such as `agent-loop`:

- use `RecallCompiler::compile()` for deterministic Recall preparation;
- pass the governed Recall envelope as `CompileRequest::$taskBrief` instead of re-reading Contract internals;
- pass optional map, Kanban, document, and operating-prompt inputs only when the host has selected those owner facts;
- consume `CompileResult` and generated evidence rather than CLI prose;
- let Recall exceptions remain explicit blockers;
- do not duplicate provider selection, conflict rules, governed binding validation, or artifact rendering in the host.

The CLI remains supported for humans, shell automation, diagnostics, and compatibility. Its text output is deliberately not the machine-to-machine PHP contract.

This boundary lets a lifecycle host own *when* deterministic preparation runs while Recall continues to own *what Recall compilation means*.
