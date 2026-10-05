# ADR 0001: Follow the llms.txt proposal

## Context

The module needs a machine-readable catalog format that remains human-readable
and can be generated without a third-party service.

## Decision

Use the current lowercase `llms.txt` Markdown proposal: H1, blockquote,
optional prose, and H2 sections containing Markdown links.

## Alternatives

- Invent a JSON schema: easier to validate, but not the requested convention.
- Publish only structured data: useful for search engines, but not a concise
  agent-oriented index.

## Consequences

The module remains simple and interoperable with proposal-aware tooling, while
the documentation must state that adoption is not guaranteed by every AI
provider.
