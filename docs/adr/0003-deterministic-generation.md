# ADR 0003: Generate deterministically without an LLM

## Context

The module must be installable without API keys, network calls, or data leaving
the merchant's shop.

## Decision

Generate content from public PrestaShop data and merchant settings only.

## Consequences

Output is reproducible, cacheable, auditable, and privacy-preserving. Editorial
quality depends on the merchant's catalog data rather than generated prose.
