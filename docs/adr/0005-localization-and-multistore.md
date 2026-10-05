# ADR 0005: Isolate runtime output by shop and language

## Context

PrestaShop multistore installations may have different domains, catalogs,
prices, and active languages even when module settings are shared.

## Decision

Use configuration stored explicitly at global scope but build each response
from the current shop and requested active full locale. Fall back per field to
the shop default language and disclose emitted merchant-content fallback once
per document. Unambiguous legacy ISO paths redirect to the full locale.

## Consequences

The same configuration is easy to operate centrally, while URLs and catalog
content cannot leak across shops.
