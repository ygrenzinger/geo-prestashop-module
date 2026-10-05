# Rich product Markdown (remediation 06)

Rich product documents are an opt-in profile on the existing localized product
Markdown route. `LLMSCATALOG_RICH_PRODUCT` defaults to `0` on install and when
added by the consolidated 1.3.0 upgrade. While it is off, the existing minimal document
body is kept. No route migration is needed.

When enabled, a product endpoint loads bounded, shop-scoped details for product
identity, descriptions, the canonical offer and availability, public
categories, features, images, combinations, pack components, and public
customization labels. Description HTML is converted to a safe Markdown subset.
Related rows are read only for that product; index queries remain lightweight.
Exact item-limit omission counts are included. The document uses the 256 KiB
default byte ceiling. Its cache identity includes the product-profile settings,
price context, shop, language, and existing product revision. The existing
freshness hooks now cover image, feature, attribute, pack, customization, and
provider changes in addition to product, combination, stock, category, and
price changes.

The canonical anonymous price and availability providers remain the source of
offer facts. No customer, cart, supplier, wholesale, physical stock quantity,
download, customer customization value, or review aggregate is read by core
rendering. The core product `date_upd` is labelled as a core-record timestamp;
it is not document `dateModified`. Rich responses keep the strong ETag and
omit `Last-Modified`/`If-Modified-Since` handling because the included sources
do not all expose reliable modification timestamps.

## Configuration

The module configuration page exposes the rich-profile switch and caps for
bytes, variants, images, specifications, categories, pack components, and
customization labels. Values are clamped to their safe ceilings on save.
Saving settings increments the configuration version and invalidates cached
documents. Settings added by the consolidated 1.3.0 upgrade are inserted only if absent, so
existing settings are preserved and rich mode remains off.

Provider IDs are an explicit comma-separated allowlist (maximum ten). Optional
ratings, shipping, returns, and warranty sections are independently off until
enabled. Providers participate through the `actionLlmsCatalogProductContributions`
hook. Each returned structured item must include `provider_id`,
`schema_version: v1`, section, matching shop/language/product IDs, `public: true`,
text, optional HTTP(S) source URL, and optional parseable `modified_at`. Unknown
keys, unenabled providers, wrong scope, private values, unsupported sections,
unsafe URLs, invalid dates, and oversized contributions are omitted. A
contribution's provider ID must match the technical ID of the module that
returned it. Providers
must use local or already-cached data and must call
`actionLlmsCatalogProductContributionChanged` after relevant changes. Never
return Markdown or HTML, customer data, or private review information.

## Rollout and rollback

1. Keep rich mode off while installing/upgrading and run the CI/test-shop gates.
2. Compare representative product types and locales against anonymous
   storefront pages, then enable in staging.
3. Enable providers only after their data provenance and invalidation behavior
   have been confirmed.
4. Enable in production through the module setting and monitor cache, response
   bytes, latency, and provider failures.
5. To roll back, turn rich mode off. The route returns to its prior minimal
   representation; no URL or table migration is required.

Implementation and focused unit-test files are present, but tests, PHP lint, and runtime verification have not been run in this workspace. The current `outputs/llmscatalog.zip` is rebuilt from the source tree and checked for archive integrity and source/archive parity. PrestaShop installation and runtime acceptance remain pending; see the [status matrix](specs/00-geo-remediation-master-plan.md) and [testing and operations](testing-and-operations.md).
