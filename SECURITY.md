# Security policy

## Reporting a vulnerability

Please open a private security advisory on GitHub or contact the maintainer
directly at lars@moelleken.org rather than filing a public issue if the
report includes vulnerability or exploit details.

## What this package does to stay safe by default

- **Deterministic Context Compilation**: Recall compilation produces reproducible
  briefings from bounded evidence, preventing prompt injection or unbounded
  context leakage.
- **Path and File Isolation**: Task IDs and file references are strictly sanitized;
  generated recall bundles stay confined to designated output directories.
- **Contextual Exceptions**: No `@` error suppression; all errors throw
  typed exceptions without leaking credentials or environment secrets.

## Supported versions

This project is pre-1.0; only the latest commit on the default branch
receives security fixes until a 1.0.0 stability policy is published.
