# User guide

## Install and upgrade

1. Back up the shop and note its installed module version.
2. Install the `llmscatalog/` folder from `outputs/llmscatalog.zip` (or the equivalent module directory) under `modules/` using Module Manager.
3. Open the module configuration page and review the settings and generated URLs.
4. Clear PrestaShop/Symfony routing caches if routes were previously cached.

The source declares version 1.5.0 and minimum PrestaShop 9.0.2. Upgrades add missing settings, preserve existing values, create cache/revision storage idempotently, and invalidate old representations. Do not downgrade a shop whose recorded module version is greater than this source version without checking its deployment history. Uninstall removes module configuration and its cache/revision tables.

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
| Augment Organization / Product / Breadcrumb structured data | Off per shop | Organization augmentation uses PrestaShop Contact details for missing contact/address data; Product fills missing Offer data or adds a canonical Product node; Breadcrumb remains missing-only. Enabling requires a completed current audit without blockers and acknowledgement that its report was reviewed. The page has no generated JSON-LD preview pane. |

Internal cache generation/version values and serialized settings are not merchant-facing controls. For title/summary fallback details and rich profile contract see [rich product Markdown](rich-product-markdown.md).

## Public URLs and traversal

The shop-root `/{base}/llms.txt` route lists active localized indexes for the current shop. Canonical routes use full locales, for example `/en-US/llms.txt`; unambiguous legacy two-letter paths redirect to the full locale. Product and catalog output is built for the current shop and requested active locale.

For a matched shop-homepage GET request, the module serves the same localized catalog Markdown when `Accept` explicitly prefers `text/markdown`. After applying the most-specific media range, Markdown must have a positive quality value higher than HTML's; for example, `Accept: text/markdown` or `Accept: text/html;q=0.2, text/markdown;q=0.9`. A missing header or wildcard-only preference keeps the HTML homepage. The Markdown response has `Content-Type: text/markdown; charset=UTF-8`, `Vary: Accept`, and `Cache-Control: private, no-store`. It does not require a separate `.md` URL. Verify the actual shop-root and language-prefixed homepage URLs on the deployment: the recorded local smoke test verified `/index.php`, while the test container redirected `/` before the hook ran.

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

Before enabling structured-data augmentation, run a complete audit for the current shop/language, ensure it has no blocker finding, review the audit report, and confirm the form checkbox. The checkbox acknowledges review; the page does not render a generated JSON-LD preview. Augmentation is per shop and disabled by default. Product augmentation fills only missing Offer fields on one canonical entity; it leaves populated conflicts, malformed JSON-LD, and ambiguous entities unchanged. When incomplete Microdata exists, the module supplements it with a canonical Product JSON-LD node. Configure the general contact email, phone, and official business address under PrestaShop **Shop Parameters > Contact > Contact details**. Organization augmentation adds a `ContactPoint` and `PostalAddress` from those values when configured. On one Organization entity whose URL or ID matches the homepage, it fills missing contact and address fields without replacing existing values; malformed or ambiguous markup is unchanged. Breadcrumb augmentation remains missing-only. An Organization description uses the current language's shop meta description, falls back to the default-language value, and is omitted when both are blank. Variant ranges use the anonymous public price context and are omitted rather than truncated when the configured variant bound is exceeded. Page augmentation performs no network requests. Switches can be disabled immediately.

## Troubleshooting

- **404 after install:** clear PrestaShop/Symfony caches, confirm friendly URLs and module enablement, and use an active full locale assigned to this shop.
- **Empty or sparse root:** confirm catalog visibility and publishing/category/product settings; root featured limits are bounded, while complete traversal has separate links.
- **Stale content:** clear PrestaShop cache, save configuration to bump generation, and allow the configured TTL/invalidation path to take effect.
- **No discovery relations:** enable discovery metadata and inspect both rendered HTML and response `Link` headers; product Markdown's own relation remains intrinsic.
- **Audit cannot fetch a page:** verify the URL resolves to an authorized shop host and responds within fetch bounds; arbitrary/external hosts are intentionally rejected.
- **Augmentation cannot be enabled:** complete the current audit, address blocker findings, review the audit report, and confirm the acknowledgement checkbox.
- **Module Manager menu opens the configuration page:** versions before 1.3.1 attached the configuration tab to the module-manager tab, so the "Module Manager" menu link led back to this module's settings instead of the module list. Upgrade to 1.3.1 (or reinstall) to reparent the tab; the configuration page also exposes a **Back** button to the module list.

## Rollback

For rich Markdown or structured-data augmentation, turn off the corresponding setting. To disable complete indexes, turn off that option; existing product Markdown URLs remain. To stop publication, disable the module setting or module. For code rollback, restore the prior module package, clear PrestaShop/Symfony caches, and purge CDN entries for localized index/product Markdown URLs and homepage variants. Homepage Markdown responses are `private, no-store`; ensure the CDN honors that policy or bypasses caching (and keys any overridden cache by `Accept`). Version 1.3.0 cache tables are additive disposable state; the earlier module can ignore them. Uninstall removes the tables and configuration. Do not restore legacy long-lived public caching without reviewing the current cache policy.

## Validation boundary

The ZIP is a regenerated source archive for review. Version 1.5.0 passed `composer lint` and `composer test` (106 tests, 10,735 assertions, 6 shop-dependent skips), and the Docker PrestaShop 9.2.0 suite passed (106 tests, 10,761 assertions). Archive checks and these tests do not establish compatibility across the supported PrestaShop version matrix; see [testing and operations](testing-and-operations.md) and the [status matrix](specs/00-geo-remediation-master-plan.md).
