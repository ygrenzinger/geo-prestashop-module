# ADR 0008: GEO audit and missing-only structured-data augmentation

## Status

Accepted for the 1.3.0 implementation; Product Offer behavior is superseded by
[ADR 0009](0009-product-offer-completion.md), and Organization contact/address
completion by [ADR 0010](0010-organization-contact-data-augmentation.md).
Runtime acceptance is pending.

## Decision

Keep GEO auditing read-only and separate from catalog publishing. Inspect final
HTML as data, cap document/sample/batch sizes, and represent observations as
serializable findings. Augmentation is disabled by default, is scoped to the
current shop, and inserts only a supported entity whose type is absent from
every parseable JSON-LD block. Malformed markup and any uncertainty cause a
no-op. Rendering performs no network request.

The implementation enables Organization, Breadcrumb, and eligible simple
Product augmentation independently per shop. Rendered-page batches, robots and
sitemap inspection, one-time XML/TSV feed comparison, and short-lived JSON
report download are present. Runtime hook, security, and compatibility
acceptance remains pending.

## Rationale

Existing theme and SEO-module output has an external owner. Replacing or
merging it risks publishing contradictory offer, identity, or breadcrumb
facts. A conflict-first no-op provides a reversible opt-in path and preserves
all source output. A deterministic local audit avoids customer data transfer,
credentials, hosted services, and opaque scoring.

## Consequences

- Audit findings need evidence, owner, expected rule, and remediation.
- Fetch adapters must enforce shop/authorized-host boundaries, limits, and
  cancellation before they are enabled.
- The augmentation switch must remain off by default and be removable without
  a database rollback.
- Unit artifacts do not establish PrestaShop hook, multistore, or theme
  compatibility; those gates remain required.
