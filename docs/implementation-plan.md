# Implementation plan

## GEO remediation 09 integration update

The 1.3.0 foundation adds audit value objects, bounded audit batches,
JSON-LD and Microdata parsing, product Offer completeness checks, rendered canonical/
hreflang/image/content inspection, robots and sitemap diagnostics, authorized
host fetching, and independent opt-in Organization, Product, and Breadcrumb
augmentation. Augmentation reads final HTML only,
does no network work, and leaves malformed, conflicting, or ambiguous markup untouched.
The admin workflow supports bounded batches, cancellation between batches,
short-lived JSON reports, and one-time XML/TSV feed snapshots. Product
augmentation fills missing Offer fields for one canonical Product and
supports complete AggregateOffer ranges for bounded variant sets. Organization
and Breadcrumb augmentation remains missing-only. The 1.4.0 update adds
Microdata inspection, Product Offer findings, and variant price/availability
aggregation. See
[specification 09](specs/09-ecommerce-geo-foundations.md).

## GEO remediation 08 implementation note

Global product, category-directory, category overview, and category-product
cursor endpoints, compact rows, root coverage counts, featured-category
settings, byte-budgeted index pages, and a configuration-preserving consolidated 1.3.0
upgrade have been added. Complete-index pages use ID cursors and avoid price
and stock hydration; the effective featured-product limit is 250 while the
saved value is preserved. Unit tests were added but deliberately not run.
Runtime validation is pending PHP CI and a PrestaShop 9 test shop. See
[specification 08](specs/08-large-catalog-completeness.md).

## GEO remediation 04 implementation note

Canonical routes and duplicate prevention are implemented in `llmscatalog`
version `1.1.0` through `1.3.0`: shop-scoped full-locale routing, legacy redirect resolution,
public-product rewrite checks, query preservation, representation and locale
Link relations, and the configuration-preserving cache invalidation upgrade
step are present. Focused tests were added but deliberately not run. Runtime
validation is pending PHP-enabled CI and a PrestaShop test shop; see
[specification 04](specs/04-canonical-routes-and-duplicates.md).

## GEO remediation 07 implementation note

Full localization is implemented in `llmscatalog` version `1.1.0`: active-shop
full-locale routes and legacy ISO redirects, reviewed English and French
catalogue dictionaries, explicit merchant-field fallback provenance and
disclosure, requested-locale price formatting, global configuration migration,
and localized administration are present. Tests were added but deliberately
not run. Runtime and static validation remain pending in PHP-enabled CI and a
PrestaShop test shop; see [specification 07](specs/07-full-localization.md).

## GEO remediation 01 implementation note

The price-context and cache-safety implementation is now represented in
`llmscatalog` version `1.1.0`: canonical anonymous pricing services,
persistent per-document cache entries, generation invalidation, and raw
conditional HTTP responses are present. Runtime validation is pending and
must pass in PHP-enabled CI and PrestaShop 9.0.2/9.1.x test shops before this
change is complete. See [the remediation spec](specs/01-price-context-and-cache-safety.md)
for the acceptance matrix and deployment gates.

## Goal

`llmscatalog` publishes a compact, localized description of a public
PrestaShop catalog for generative systems. The module is deterministic: every
line is derived from current public shop data or merchant configuration.

## Non-goals

- It does not call an LLM or send catalog data to a third party.
- It does not replace `robots.txt`, the XML sitemap, structured data, or normal
  SEO work.
- It does not promise that a crawler or AI search product will fetch or use the
  file.
- It does not expose customer-group pricing, private products, orders, or
  customer information.

## Functional contract

### Public endpoint matrix

All localized public routes require an active full locale assigned to the current shop. Unambiguous legacy ISO paths redirect to the canonical full locale. Public endpoints accept GET and HEAD through the front controllers; response content types and cache policy are selected by document type.

| Route | Response | Purpose / behavior |
| --- | --- | --- |
| `/llms.txt` | `text/plain; charset=UTF-8` | Shop-root directory of active locale indexes, scoped to the current shop domain/base URI. |
| `/{locale}/llms.txt` | `text/plain; charset=UTF-8` | Curated root, coverage counts, featured content, and complete-index entry points. |
| `/{locale}/llms/product/{id}/{rewrite}.md` | `text/markdown; charset=UTF-8` | Minimal or configured rich product representation; stale rewrite redirects, unavailable product is 404. |
| `/{locale}/llms/catalog/products/after/{cursor}.md` | `text/markdown; charset=UTF-8` | Global complete product traversal, ascending product ID. Cursor `0` starts traversal. |
| `/{locale}/llms/catalog/categories/after/{cursor}.md` | `text/markdown; charset=UTF-8` | Active-category directory, ascending category ID. |
| `/{locale}/llms/category/{id}/{rewrite}.md` | `text/markdown; charset=UTF-8` | Category overview, canonical HTML URL, eligible count, and first product-page link. |
| `/{locale}/llms/category/{id}/{rewrite}/products/after/{cursor}.md` | `text/markdown; charset=UTF-8` | Complete eligible products assigned to one category. |

Catalog traversal routes require `LLMSCATALOG_COMPLETE_INDEXES`; disabling it returns 404 for those routes. They use bounded ID cursors and compact rows without price/stock hydration. Category membership may repeat products across category pages; global traversal lists each eligible product once. See [large-catalog completeness](specs/08-large-catalog-completeness.md) for detailed edge behavior.

### Index rules

The index contains an H1, a blockquote summary, explanatory context, a
category section, and a product section. Products are active and visible in
the current shop, are ordered by catalog position, and are bounded by the
configured product and byte limits.

The default limits are 250 products and 128 KiB. Truncation is explicit and
does not produce malformed Markdown.

### Product rules

Product Markdown contains the product name, canonical HTML URL, short
description, canonical public price, currency, and resolved availability.
Availability is shared across Markdown and storefront Product/Offer structured
data, uses the default shop combination and PrestaShop pack-aware stock path,
and never publishes quantities. Price rendering
uses the shop's default public context.

## Configuration contract

Configuration is shared across shops. Generated documents are still isolated
by shop and language because catalog data, URLs, currency, and language are
shop-specific.

The Symfony configuration page controls publishing, localized title and
summary, limits, TTL, sections, price and availability fields, and optional
HTML discovery links. Form processing uses Symfony's CSRF protection.

## Cache and invalidation

Versioned cache keys partition document type, shop, language, product,
configuration version, cache generation, and canonical public-price
fingerprint. Product, category, stock, pricing, currency, group, tax, language,
shop, and module-setting changes bump cache generation. Price-bearing documents
are bounded by configured TTL and the next finite public-price transition; successful HTTP
responses require browser revalidation and errors/degraded documents are
`no-store`. Runtime validation remains pending.

## Delivery sequence

1. Install the module and register routes/hooks.
2. Verify the configuration page and language defaults.
3. Preview each active language's index.
4. Validate product Markdown and cache behavior in a PrestaShop 9 test shop.
5. Package the module directory as a ZIP with the directory preserved.

## Acceptance criteria

- The module installs on supported PrestaShop 9.x releases.
- Each active language resolves to a valid localized index.
- Product links resolve to public Markdown responses.
- Output contains no private catalog data.
- Limits, fallback, cache invalidation, and CSRF behavior are covered by tests
  or the documented smoke-test procedure.
