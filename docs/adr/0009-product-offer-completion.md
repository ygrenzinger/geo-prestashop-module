# ADR 0009: Complete missing Product Offer data

## Status

Accepted for the 1.4.0 implementation; PrestaShop runtime acceptance is pending.

## Decision

The product structured-data audit inspects JSON-LD and Microdata for public
Offer completeness. With per-shop augmentation enabled, the module fills only
missing Offer properties on one canonical Product JSON-LD entity. If incomplete
Microdata is present, it remains intact and the module emits a complete Product
JSON-LD entity keyed to the canonical URL. Populated conflicting values,
malformed JSON-LD, and ambiguous entities are left unchanged.

Simple products use Offer with public price, currency, and availability.
Products with shop combinations use AggregateOffer with the complete low/high
price range and aggregate availability, calculated in the anonymous public
price context. The existing configured variant limit bounds calculation. If
the complete set cannot be priced, no partial range is emitted.

Product augmentation stays disabled by default and uses the existing audit and
confirmation gate. Organization and Breadcrumb augmentation remain
missing-only. No database migration or new configuration key is required.

## Rationale

Storefront Product data may be incomplete even when a theme owns the entity.
Filling absent properties fixes the public data gap while preserving values
that may reflect theme-specific policy. Variant ranges must use the same
anonymous price and stock decisions as the public catalog, and must fail closed
when the full range is unavailable.

## Consequences

- Audit reports identify missing Product, Offer, price, currency, availability,
  and variant range data.
- Structured-data mutation remains bounded to the canonical product page and
  the per-shop opt-in.
- A variant set above the configured limit or with a failed price calculation
  remains an explicit unresolved audit finding.
- Unit coverage does not replace hook, theme, and multistore validation in a
  PrestaShop test shop.
