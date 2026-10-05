# ADR 0004: Bound the index and link to product Markdown

## Context

An ecommerce catalog can exceed the practical context size of a concise index.

## Decision

Limit the index by product count and bytes, then link to product-specific
Markdown responses for detail. Use 250 products and 128 KiB as safe defaults.

## Consequences

The index remains fast and context-friendly. Merchants can increase limits,
and agents can retrieve product details on demand.
