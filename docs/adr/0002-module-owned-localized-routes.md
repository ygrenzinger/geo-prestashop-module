# ADR 0002: Use module-owned localized routes

## Context

Native product URLs are controlled by the core and themes. Adding `.md` to
those URLs would require product-controller overrides or web-server rewrites.

## Decision

Use stable module-owned routes under `/{locale}/llms/` and register them through
`moduleRoutes`. Canonical locale segments use normalized full PrestaShop locale
values such as `fr-FR`; unique legacy ISO and duplicate-language paths redirect
to that route after shop-scoped language and resource validation.

## Consequences

The module avoids invasive overrides and can be removed cleanly. The Markdown
representation is self-canonical, and it also links to the canonical HTML
product entity. Those relations describe distinct representations.
