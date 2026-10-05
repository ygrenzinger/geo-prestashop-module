# Large catalog completeness and scalable `llms.txt` indexes

## Status

Implemented in the approved module version `1.2.0` (folded into the consolidated `1.3.0` candidate); unit and runtime validation have not
been run. PHP-enabled CI and PrestaShop 9.0.2/9.1.x test-shop gates remain
pending, including query-plan and query-count measurements on a 10,000-product
fixture.

## Objective

Make every eligible public product discoverable through bounded, deterministic
Markdown indexes for shops with up to 10,000 public products, without turning
the localized root `llms.txt` into an exhaustive or unbounded document.

The design preserves:

- deterministic generation without an external LLM;
- current shop, language, and multistore isolation;
- existing product Markdown URLs;
- merchant/catalog positions for featured content;
- the configured byte limit;
- concise root indexes that fit practical agent context windows.

The root remains curated. Complete coverage is provided through linked,
cursor-paginated catalog and category indexes.

## Current-state problems

The current implementation has four completeness and scaling problems:

1. `CatalogRepository::products()` applies `LIMIT max_products` without
   obtaining the eligible total. Products after that limit disappear silently.
2. Categories are loaded without a limit and are rendered before products. A
   large taxonomy can consume the byte budget and remove the product section.
3. Byte truncation provides a generic warning but no total, omitted count, or
   traversal URL.
4. Index generation performs price and availability work for every selected
   product. This scales with the root product limit and must never scale with
   the complete catalog.

Relevant current sources:

- `../../llmscatalog/src/Service/CatalogRepository.php`
- `../../llmscatalog/src/Service/LlmsGenerator.php`
- `../../llmscatalog/src/Service/MarkdownRenderer.php`
- `../../llmscatalog/src/Service/CacheManager.php`
- `../../llmscatalog/llmscatalog.php`

## Design target

The supported performance target is 10,000 eligible public products per shop.

Catalogs larger than 10,000 products continue to use the same bounded cursor
traversal and must not produce unbounded responses. They are functionally
supported, but the initial release makes no latency SLA beyond 10,000 products.

An eligible product is:

- assigned to the current shop;
- active in that shop;
- visible with `visibility <> 'none'`;
- rendered using the requested language with field-level fallback to the shop
  default language.

A missing localized name must not make a product unreachable. The fallback
label is `Product #<id>`.

## External contract

The current `llms.txt` proposal expects the root document to stay small and
place detail behind links. It explicitly distinguishes this use from an
exhaustive XML sitemap. See [the `llms.txt` v2 proposal](https://llmstxt.org/)
and [the Sitemap protocol](https://www.sitemaps.org/protocol.html).

The module therefore exposes two complementary layers:

- `llms.txt`: curated guidance, coverage information, and entry points into the
  catalog;
- complete Markdown indexes: deterministic traversal to every eligible product;
- sitemap: canonical HTML URL discovery for search engines, owned by the shop's
  sitemap implementation.

The module does not generate, parse, replace, or claim parity with the XML
sitemap. Complete `llmscatalog` coverage refers to eligible product Markdown
endpoints, not every indexable shop URL.

## Route contract

Existing routes remain valid:

| Route | Purpose |
| --- | --- |
| `/{locale}/llms.txt` | Curated root and complete-catalog entry points |
| `/{locale}/llms/product/{id}/{rewrite}.md` | Product detail Markdown |

Add these routes:

| Route | Purpose |
| --- | --- |
| `/{locale}/llms/catalog/products/after/{cursor}.md` | Complete products ordered by product ID |
| `/{locale}/llms/catalog/categories/after/{cursor}.md` | Complete active-category directory |
| `/{locale}/llms/category/{id}/{rewrite}.md` | Category overview and product count |
| `/{locale}/llms/category/{id}/{rewrite}/products/after/{cursor}.md` | Complete products assigned to one category |

`cursor` is a non-negative numeric ID. The initial page uses `0`.

Pagination uses `id > cursor ORDER BY id ASC`, never SQL offset pagination.
Each next link uses the last product or category ID actually rendered, not the
last row fetched.

This provides stable traversal when earlier records are deleted and prevents
duplicate or skipped rows caused by shifting offsets. Auto-incremented products
added during traversal appear after the relevant cursor. Reactivating an older
ID can require restarting at the root.

## Root `llms.txt`

The root document uses this priority order:

1. H1 title.
2. Blockquote summary.
3. Eligibility and catalog-version explanation.
4. Coverage totals and omission notices.
5. Complete catalog entry points.
6. Featured categories.
7. Featured products.
8. Optional explanatory links.

Required coverage content is reserved before featured content is rendered.

Example:

```markdown
Catalog version: 42.

Eligible products: 10,000.
Featured on this page: 250.
Omitted from this page: 9,750.
All eligible products are reachable through Complete catalog.

Active categories: 420.
Featured on this page: 50.
Omitted from this page: 370.
All active categories are reachable through Category directory.
```

The wording must distinguish "omitted from this root page" from "excluded from
the catalog."

If product indexing is disabled, the root explicitly says that eligible
products are omitted because indexing is disabled. It must not claim complete
coverage.

## Featured versus complete ordering

Featured content follows existing merchant ordering:

- featured categories: category depth, merchant position, category ID;
- featured products: `product_shop.position`, product ID.

This preserves the current curation signal. Explicit pins, sales ranking,
popularity, personalization, or automated business scoring are deferred.

Complete traversal prioritizes stability:

- complete products: product ID ascending;
- category directory: category ID ascending;
- category products: product ID ascending.

Category membership uses the customer-facing `category_product` association. A
product may appear in multiple category indexes, but appears exactly once in
the global complete-product traversal.

The global product traversal is the authoritative completeness backbone.
Category indexes provide navigation and do not determine whether a product is
covered.

## Page and payload bounds

Defaults and hard bounds:

| Setting | Decision |
| --- | --- |
| Root featured products | Existing `LLMSCATALOG_MAX_PRODUCTS`, effective maximum 250 |
| Root featured categories | New default 50, effective maximum 250 |
| Cursor page row target | 100 |
| Default maximum document size | Existing 128 KiB |
| Existing minimum byte setting | Preserve 1 KiB for compatibility; warn below 16 KiB |
| Product/category label limit | 200 Unicode characters |
| Complete-index descriptions | Omitted |
| Featured short description | Normalize and limit to 240 Unicode characters |

Every document reserves space for:

- title and summary;
- coverage counts;
- root/back link;
- next-page link;
- omission or completion notice.

Rows are appended only while the reserved footer still fits. If fewer than 100
rows fit, the next cursor is the ID of the last rendered row. The following
page therefore begins with the first unrendered row.

Raw byte slicing is not allowed for structured indexes. The renderer must build
documents section-by-section and line-by-line so it never cuts UTF-8, Markdown
syntax, or navigation links.

A complete page must state:

- total eligible records;
- records rendered on this page;
- records remaining after this page;
- current catalog version;
- next link, when records remain.

No timestamp is included because it would make unchanged output
nondeterministic.

## Category behavior

The root renders only the bounded featured-category set.

The complete category directory uses category-ID cursor pagination. Each entry
links to a category Markdown overview.

A category overview contains:

- localized category name;
- canonical public HTML category URL;
- parent category link when applicable;
- number of eligible assigned products;
- link to the first category-product page.

It does not embed an unbounded product list.

An active category with no eligible products returns `200` with an explicit
zero count. A category-product cursor beyond the last member returns `404`,
except cursor `0`, which may return the valid empty category index.

## Product detail and URL lifecycle

Product Markdown routes remain ID-based. The rewrite is descriptive, not
identity.

Behavior:

- current ID and current rewrite: `200`;
- current ID and stale rewrite: `301` to the localized canonical Markdown URL;
- deleted, inactive, hidden, or unavailable-in-current-shop product: `404`;
- moved to another category: product Markdown URL remains unchanged;
- removed from a category: disappears from that category after invalidation but
  remains in the global catalog;
- category with a stale rewrite: `301`;
- deleted, inactive, or unavailable-in-current-shop category: `404`;
- malformed ID or cursor: `404`.

The module does not store tombstones, so it cannot reliably distinguish deleted
resources from IDs that never existed. It therefore uses `404`, not `410`.

Every child Markdown response includes an HTTP `Link` header pointing to the
localized root with `rel="describedby"`.

## Catalog version and concurrent changes

Each product, category, association, or relevant configuration change
increments the catalog version used by cache keys and displayed in index
documents.

A traversal is deterministic for one catalog version. The system does not
persist immutable catalog snapshots.

If the displayed version changes between pages, an agent can restart from
cursor `0`. Responses remain individually correct and bounded even if the
catalog changes during traversal.

External HTTP caches may serve the previous version until the configured TTL
expires. This is an accepted bounded-staleness condition.

## Repository and query design

Add repository operations for:

- total eligible product count;
- bounded featured products;
- products after a product-ID cursor;
- total active category count;
- bounded featured categories;
- categories after a category-ID cursor;
- category metadata and canonical rewrite;
- eligible product count for a category;
- category products after a product-ID cursor.

Cursor selects request at most 101 rows. The extra row determines whether
another page exists.

Complete index rows contain only fields needed for labels and Markdown URLs.
They do not calculate price, availability, or descriptions.

Availability for featured products must be obtained through a shop-scoped
batch join or batch lookup. The existing per-product stock call must not remain
in index generation.

Correct contextual public prices continue to use PrestaShop's price API for
featured root products and product detail pages. This cost is bounded by the
effective featured limit of 250 and is never paid for complete index pages.

Cold-cache query budgets:

| Document | Budget |
| --- | --- |
| Root | Two counts, one category select, one product select, plus at most one price calculation per rendered featured product |
| Complete product page | One count and one bounded select |
| Category directory page | One count and one bounded select |
| Category overview | One category lookup and one product count |
| Category product page | One category validation, one count, one bounded select |
| Product detail | One bounded product lookup plus its contextual price calculation |

Cache hits perform no catalog repository queries.

Implementation must inspect query plans against a 10,000-product fixture. It
should use existing PrestaShop indexes and must not add application tables or
database indexes without evidence from those query plans.

## Cache design

Cache identity must include:

- shop ID;
- language ID;
- catalog/configuration version;
- document type;
- category ID when relevant;
- cursor when relevant.

The current root-only cache key is insufficient for multiple index documents.

Known cache keys must be partitioned by shop and version or otherwise kept
bounded. Catalog versioning remains the correctness mechanism; tracked-key
deletion is best-effort reclamation.

Cache invalidation must cover:

- product create, update, delete, activation, visibility, and shop association;
- category create, update, delete, activation, position, and shop association;
- product-category association changes;
- relevant price or stock changes when supported by verified PrestaShop 9 hooks;
- module configuration changes.

The TTL remains the safety net for unhandled or externally applied changes.

Controller `Cache-Control` headers must use the configured TTL rather than the
currently hard-coded 3,600 seconds.

## Settings and migration

Add:

- `LLMSCATALOG_COMPLETE_INDEXES`, default enabled;
- `LLMSCATALOG_MAX_FEATURED_CATEGORIES`, default 50;
- `LLMSCATALOG_CATALOG_VERSION`, if separated from the existing configuration
  version.

Preserve existing values for:

- enabled state;
- localized titles and summaries;
- maximum products;
- maximum bytes;
- cache TTL;
- included sections;
- price and availability;
- discovery-link injection.

`LLMSCATALOG_MAX_PRODUCTS` becomes explicitly documented as the root
featured-product limit. Values above 250 remain stored but have an effective
runtime maximum of 250. The administration page displays both configured and
effective values and warns rather than silently changing saved configuration.

`include_price` and `include_availability` continue to control featured root
descriptions and product detail documents. Complete traversal pages always
remain compact.

No custom database table is introduced.

## Backward compatibility

The following remain stable:

- localized root URL;
- product Markdown URL structure;
- eligibility definition;
- localized field fallback;
- product detail content contract;
- shared settings with shop-isolated output;
- default 250-product and 128-KiB settings;
- disabled-module behavior;
- public content types.

The root content gains counts, complete-index links, and bounded categories.
Clients that parse valid `llms.txt` H2 link sections remain compatible.

Temporary rollback support keeps a `complete_indexes` configuration switch.
When disabled:

- new index routes return `404`;
- the root uses the legacy single-document behavior;
- the administration page warns that products beyond the root limits are not
  reachable through `llmscatalog`.

This compatibility switch should remain for one minor release and can be
reconsidered after field validation.

## Sitemap complementarity

The XML sitemap and `llmscatalog` solve different problems:

| Sitemap | `llmscatalog` |
| --- | --- |
| Exhaustive canonical HTML URL discovery | Concise agent guidance and Markdown discovery |
| Search-engine protocol | Human- and LLM-readable Markdown proposal |
| Large URL sets and sitemap indexes | Small root plus bounded follow-up documents |
| Primarily canonical shop URLs | Module-owned Markdown representations |

The module must not advertise its Markdown cursor pages as XML sitemap
replacements. Product Markdown documents retain their canonical HTML product
URL.

If another module supplies the shop sitemap, no coupling or dependency is
introduced.

## Test plan

### Unit tests

Cover:

- featured ordering with position and ID tie-breakers;
- complete ordering by ID;
- cursor `0`, middle cursor, last cursor, and beyond-last cursor;
- page boundaries at 0, 1, 99, 100, 101, and 10,000 products;
- no duplicate or omitted IDs across an unchanged traversal;
- deletion before a cursor;
- insertion after a cursor;
- explicit root totals and omitted counts;
- explicit page rendered and remaining counts;
- category totals and limits;
- multiple category membership without duplication in the global traversal;
- next cursor based on the last rendered row;
- byte exhaustion before the 100-row target;
- reserved next-link and notice space;
- UTF-8 and Markdown-safe label truncation;
- missing localized names and rewrites;
- stale rewrite redirects;
- deleted-resource `404` responses;
- cache-key isolation by shop, language, document, entity, cursor, and version;
- settings migration and effective-limit warnings;
- legacy mode.

### Integration tests

On PrestaShop 9:

- build a 10,000-product catalog with multiple languages and shops;
- traverse the complete global index and assert every eligible product appears
  exactly once;
- assert inactive, hidden, and cross-shop products never appear;
- traverse category directories and large categories;
- mutate products and categories and verify version and cache changes;
- change a rewrite and verify `301`;
- delete a product and verify `404`;
- change category membership and verify the global URL remains stable;
- verify contextual price correctness for featured and detail documents;
- instrument database queries and enforce the documented budgets;
- verify cold and warm generation memory usage;
- verify every response stays within its byte limit;
- verify HTTP content type, `Link`, cache, and `nosniff` headers;
- verify low byte limits still preserve forward traversal.

### Regression tests

Keep the existing Markdown normalization, escaping, and truncation tests, but
replace raw structured-document truncation with the budget-aware renderer.

Verify existing root and product URLs before and after upgrade.

## Acceptance criteria

The work is complete when:

1. Every eligible product in a stable 10,000-product fixture is reachable
   exactly once through the global cursor chain.
2. The root explicitly reports eligible, featured, and root-omitted counts.
3. Categories cannot consume the product or complete-navigation budget.
4. Every response respects the configured byte limit without malformed
   Markdown.
5. Complete pages perform no per-product price or stock lookups.
6. Cursor traversal uses no SQL offset.
7. Existing root and product URLs remain valid.
8. Deleted and renamed resources follow the documented HTTP behavior.
9. Cache keys isolate all shops, languages, document types, cursors, and
   versions.
10. Sitemap responsibilities remain independent.
11. PHP lint, PHPUnit, and PrestaShop smoke tests pass in a PHP-enabled
    environment.

## Implementation sequence

1. Add settings, migration defaults, and administration warnings.
2. Add count and cursor repository methods.
3. Separate compact index rows from enriched product details.
4. Replace per-row stock access with a batch operation for featured products.
5. Extend URL generation and module routes.
6. Implement complete-product, category-directory, category-overview, and
   category-product controllers.
7. Implement the budget-aware document renderer and explicit coverage notices.
8. Extend cache identity and invalidation.
9. Add unit and integration fixtures, including 10,000 products.
10. Run query-plan, query-count, byte-limit, multistore, and localization
    validation.
11. Update architecture, operations, ADR, and packaging documentation.
12. Enable complete indexes, retain the rollback switch for one minor release,
    and monitor cold-generation latency and cache behavior.

## Rollout and rollback

Release behind `LLMSCATALOG_COMPLETE_INDEXES`.

For new installations, enable it by default. For upgrades, enable it during
migration while preserving a merchant-controlled rollback switch.

Before production rollout:

- validate on a representative 10,000-product test shop;
- record cold and warm timings;
- record query counts for each document type;
- confirm root and page byte sizes;
- test cache invalidation under catalog changes.

Rollback consists of disabling complete indexes, clearing module caches, and
returning the root to legacy rendering. Existing product Markdown URLs remain
unaffected.

Because the current workspace has no PHP, Composer, or PrestaShop runtime,
implementation cannot be considered validated until these checks run in CI or
a real PrestaShop 9 test shop.
