# User guide

## Install and upgrade

1. Back up the shop and note its installed module version.
2. Install the `llmscatalog/` folder from `outputs/llmscatalog.zip` (or the equivalent module directory) under `modules/` using Module Manager.
3. Open the module configuration page and review the settings and generated URLs.
4. Clear PrestaShop/Symfony routing caches if routes were previously cached.

The source declares version 1.3.0 and minimum PrestaShop 9.0.2. Upgrades add missing settings, preserve existing values, create cache/revision storage idempotently, and invalidate old representations. Do not downgrade a shop whose recorded module version is greater than this source version without checking its deployment history. Uninstall removes module configuration and its cache/revision tables.

## Configuration

Settings on the main publishing form are global module settings. Output remains isolated by current shop and language. JSON-LD augmentation switches are stored per shop. New-install defaults below come from module installation defaults; existing installations retain their saved values during upgrades.

| Option | New-install default | Effect |
|---|---:|---|
| Publish `llms.txt` (`LLMSCATALOG_ENABLED`) | On | Enables public root/localized publishing. |
| Localized title and summary | Shop name and language-specific meta description copied at install | Each active language can be edited independently. If a value is blank, title falls back to shop-default language then shop name; summary falls back to shop-default language then meta description. |
| Maximum featured products (`MAX_PRODUCTS`) | 250 | Root featured-product cap; effective value is clamped to 1–250. |
| Maximum output bytes (`MAX_BYTES`) | 131072 (128 KiB) | Root output budget; minimum accepted value is 1024 bytes. |
| Cache TTL (`CACHE_TTL`) | 3600 seconds | Shared document freshness upper bound; minimum 60 seconds. |
| Include categories/products | On / On | Controls featured root sections and their document content. |
| Include public price/availability | On / On | Includes canonical anonymous public offer facts, without exact stock quantity. |
| Publish discovery metadata (`INJECT_LINKS`) | On | Adds equivalent HTML and HTTP relations: homepage/category `describedby`; eligible product `alternate` then `describedby`. |
| Complete catalog indexes (`COMPLETE_INDEXES`) | On | Adds global and category ID-cursor traversal links/routes. Disable to make these routes unavailable and restore legacy root behavior. |
| Maximum featured categories | 50 | Root featured category cap, clamped to 1–250. |
| Rich product Markdown | Off | Enables bounded extended product detail profile. Off restores the minimal document immediately. |
| Product byte limit | 262144 | Rich document ceiling, clamped to 1 KiB–256 KiB. |
| Maximum variants / images / specifications / categories / pack items / custom fields | 50 / 8 / 50 / 10 / 50 / 25 | Bounds rich product sections; hard maxima are respectively 200 / 50 / 200 / 50 / 200 / 100. |
| Enabled provider IDs | Empty | Up to ten validated provider identifiers, comma separated. |
| Provider sections: ratings, shipping, returns, warranty | Off | Permits corresponding structured provider sections when available. |
| Augment Organization / Product / Breadcrumb JSON-LD | Off per shop | Enables missing-only augmentation for supported unambiguous pages. A completed current audit with no blocker findings and preview confirmation are required before enabling. |

Internal cache generation/version values and serialized settings are not merchant-facing controls. For title/summary fallback details and rich profile contract see [rich product Markdown](rich-product-markdown.md).

## Public URLs and traversal

The shop-root `/{base}/llms.txt` route lists active localized indexes for the current shop. Canonical routes use full locales, for example `/en-US/llms.txt`; unambiguous legacy two-letter paths redirect to the full locale. Product and catalog output is built for the current shop and requested active locale.

| Route | Purpose |
|---|---|
| `/llms.txt` | Shop-root language directory |
| `/{locale}/llms.txt` | Curated root index and traversal entry points |
| `/{locale}/llms/product/{id}/{rewrite}.md` | Product Markdown |
| `/{locale}/llms/catalog/products/after/{cursor}.md` | Complete eligible products in ascending ID order |
| `/{locale}/llms/catalog/categories/after/{cursor}.md` | Complete active-category directory |
| `/{locale}/llms/category/{id}/{rewrite}.md` | Category overview and eligible product count |
| `/{locale}/llms/category/{id}/{rewrite}/products/after/{cursor}.md` | Products assigned to that category |

Start cursor traversal at `0`; follow each emitted next link. Pages use ID cursors, so deleted earlier rows do not shift subsequent pages. Category traversal is navigation; the global product traversal is the completeness backbone. Complete index pages are compact and omit price, stock, and descriptions. The product and category URLs in Markdown point to canonical storefront resources. This module does not create or modify `robots.txt` or XML sitemaps. Check existing robots policy does not block the module routes and add desired URLs to the shop's existing sitemap process.

## Product Markdown

Minimal product output describes the public product name, canonical product URL, description, and configured public price/availability. The optional rich profile adds bounded structured details such as identifiers, variants, images, specifications, assigned categories, pack components, custom fields, and explicitly enabled provider sections. It uses public shop data and never exposes customer/private review data. Provider integrations must supply validated public scoped fields and signal changes using the documented hook. See [rich product Markdown](rich-product-markdown.md).

## Read-only audit and feed snapshot

The configuration page's audit is scoped to the current shop and language. It samples the homepage, up to 10 categories, and up to 25 visible products, in batches of at most five. It checks rendered canonical/hreflang, content/image, structured-data, robots, sitemap, and related signals; it does not edit those surfaces. The report is retained in the back-office session for 15 minutes and can be downloaded as JSON. Batches can be cancelled.

Feed comparison accepts a one-time XML or TSV snapshot up to 2 MiB. The uploaded file is processed for comparison and is not persisted as a feed. Fetching is restricted to authorized shop/feed hosts; arbitrary URLs are rejected.

Before enabling JSON-LD augmentation, run a complete audit for the current shop/language, ensure it has no blocker finding, review the proposed augmentation preview and confirm the form checkbox. Augmentation is per shop, disabled by default, and missing-only: existing or malformed JSON-LD and ambiguous pages are left unchanged. It performs no network requests during page augmentation. Switches can be disabled immediately.

## Troubleshooting

- **404 after install:** clear PrestaShop/Symfony caches, confirm friendly URLs and module enablement, and use an active full locale assigned to this shop.
- **Empty or sparse root:** confirm catalog visibility and publishing/category/product settings; root featured limits are bounded, while complete traversal has separate links.
- **Stale content:** clear PrestaShop cache, save configuration to bump generation, and allow the configured TTL/invalidation path to take effect.
- **No discovery relations:** enable discovery metadata and inspect both rendered HTML and response `Link` headers; product Markdown's own relation remains intrinsic.
- **Audit cannot fetch a page:** verify the URL resolves to an authorized shop host and responds within fetch bounds; arbitrary/external hosts are intentionally rejected.
- **Augmentation cannot be enabled:** complete the current audit, address blocker findings, then review and confirm the preview.

## Rollback

For rich Markdown or JSON-LD augmentation, turn off the corresponding setting. To disable complete indexes, turn off that option; existing product Markdown URLs remain. To stop publication, disable the module setting or module. For code rollback, restore the prior module package, clear PrestaShop/Symfony caches, and purge CDN entries for localized index/product Markdown URLs. Version 1.3.0 cache tables are additive disposable state; the earlier module can ignore them. Uninstall removes the tables and configuration. Do not restore legacy long-lived public caching without reviewing the current cache policy.

## Validation boundary

The ZIP is a regenerated source archive for review. Its existence and archive checks do not establish successful PHP execution, PrestaShop installation, upgrade, hook, security, or runtime compatibility; those gates remain pending PHP-enabled CI and supported test shops.
