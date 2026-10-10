# ADR 0010: Organization contact data augmentation

## Status

Accepted for the 1.5.0 implementation. This supersedes the missing-only
Organization decision in [ADR 0008](0008-geo-audit-and-missing-only-augmentation.md).

## Decision

Keep Organization augmentation disabled by default and scoped to the current
shop. Build `contactPoint` and `PostalAddress` from PrestaShop's official shop
Contact details. Emit only configured values; use `customer service` as the
contact type for the shop's general contact email and phone.

When the homepage already has exactly one Organization-compatible JSON-LD
entity whose `url` or `@id` identifies the current homepage, fill its missing
contact and postal-address fields. Preserve every populated value. Fill a
single matching customer-service ContactPoint or add one alongside specialized
contact points. Fill one unambiguous PostalAddress. If the entity or nested
property is malformed or ambiguous, leave it unchanged. If no compatible
Organization exists, add the candidate node under the existing canonical,
noindex, size, and parsing checks.

## Rationale

PrestaShop already stores the official public contact details merchants
maintain for their shop. Reusing those settings avoids duplicate configuration
and keeps the structured data aligned with the contact information shown to
customers. Field-level completion provides that data when a theme already
owns the Organization entity, while preserving its populated facts.

## Consequences

- No module settings or database migration are required; the existing per-shop
  opt-in and audit gate continue to control augmentation.
- Empty or invalid email and absent contact details are omitted. Address parts
  are emitted only when configured; the shop country falls back to the
  configured default country when necessary.
- Malformed JSON-LD, ambiguous Organization matches, and ambiguous nested
  contact/address structures remain unchanged.
- Unit and PrestaShop integration tests cover candidate construction and
  conflict-preserving augmentation. Runtime validation remains bounded by the
  supported PrestaShop and theme matrix.
