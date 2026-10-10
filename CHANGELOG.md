# Changelog

User-visible changes for the `llmscatalog` PrestaShop module. Current validation
and pending compatibility gates are tracked in the [remediation status
matrix](docs/specs/00-geo-remediation-master-plan.md).

## 1.5.0 — 2026-10-10

- Add Organization `contactPoint` and `PostalAddress` data from PrestaShop's
  existing shop contact settings.
- Complete missing contact and address fields on one homepage-matched
  Organization entity while preserving populated values and leaving malformed
  or ambiguous markup unchanged.
- Keep augmentation opt-in per shop; no new settings or migration are needed.
- `composer lint` passed; `composer test` passed (106 tests, 10,735 assertions,
  6 shop-dependent skips); the Docker PrestaShop 9.2.0 suite passed (106 tests,
  10,761 assertions). The archive passed integrity and source-parity checks.

## 1.4.1 — 2026-10-10

- Document per-commit documentation, changelog, and Semantic Versioning
  requirements in `AGENTS.md`.
- Synchronize module version metadata and regenerate the source ZIP. No
  storefront behavior, settings, schema, or upgrade behavior changed.
- PHP suites were not rerun; the latest PHP validation covers the 1.4.0
  implementation.

## 1.4.0 — 2026-10-10

- Audit Product JSON-LD and Microdata for missing public Offer data.
- Optionally fill missing Offer fields on one canonical Product entity, or add
  canonical Product JSON-LD when incomplete Microdata is present. Existing
  conflicts, malformed JSON-LD, and ambiguous entities remain unchanged.
- Support bounded `AggregateOffer` price ranges for products with combinations;
  omit a range when the full set cannot be calculated within the configured
  variant limit.
- Add the localized shop meta description to generated Organization JSON-LD,
  falling back to the default language and omitting the field when both are
  blank.
- Structured-data augmentation remains disabled by default. No new setting or
  database migration is required.

## 1.3.2 — 2026-10-10

- Serve the localized catalog Markdown from a matched shop-homepage GET request
  when `Accept` explicitly prefers `text/markdown`.
- Return `Vary: Accept` and `Cache-Control: private, no-store` for the Markdown
  representation; ordinary HTML requests keep the storefront homepage.
- The local smoke check verified `/index.php`. Root and language-prefixed
  homepage routing still require verification on a correctly routed test shop.

## 1.3.1 — 2026-10-09

- Improve PrestaShop 9 compatibility for module services and front controllers.
- Derive public product-condition labels from PrestaShop core behavior.
- Fix the back-office navigation hierarchy so the Module Manager link returns
  to the module list, with a Back link available from configuration.
